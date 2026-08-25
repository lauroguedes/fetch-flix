<?php

namespace App\Media\Discovery;

use App\Api\Exceptions\ApiException;
use App\Api\UsageTracker;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Gates every outbound Data API call against two budgets, tenant and global.
 *
 * search.list costs 100 units against a 10,000/day quota: roughly 100 searches
 * a day for the entire project. Quota is a shared exhaustible resource, not a
 * rate limit, and is accounted for as one.
 */
final class QuotaGuard
{
    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_GLOBAL = 'global';

    public function __construct(
        private readonly UsageTracker $tracker,
    ) {}

    /**
     * Reserve units, run the call, and record the spend.
     *
     * Reserved *before* the call: incrementing afterwards would let a burst of
     * concurrent requests all pass the check and blow the allowance together.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function spend(Tenant $tenant, int $units, Closure $callback): mixed
    {
        $this->reserve($tenant, $units);

        // No rollback on failure, by design: Google charges for invalid
        // requests too, so releasing the reservation would let a failing loop
        // burn the real allowance while our counters said otherwise.
        return $callback();
    }

    private function reserve(Tenant $tenant, int $units): void
    {
        $ttl = $this->secondsUntilReset();

        $globalLimit = (int) config('media.quota.daily_limit', 10000);
        $global = $this->increment($this->globalKey(), $units, $ttl);

        if ($global > $globalLimit) {
            throw ApiException::rateLimited(
                retryAfter: $ttl,
                scope: self::SCOPE_GLOBAL,
                message: 'The daily discovery quota for this service is exhausted.',
            );
        }

        $tenantCount = $this->increment($this->tenantKey($tenant), $units, $ttl);

        // Recorded even when the call fails: Google charges either way.
        $this->tracker->spendUnits($units);

        if ($tenantCount > $tenant->quota_units_daily) {
            throw ApiException::rateLimited(
                retryAfter: $ttl,
                scope: self::SCOPE_TENANT,
                message: 'This tenant has exhausted its daily discovery quota.',
            );
        }
    }

    public function remainingForTenant(Tenant $tenant): int
    {
        return max(0, $tenant->quota_units_daily - $this->spent($this->tenantKey($tenant)));
    }

    public function remainingGlobally(): int
    {
        return max(0, (int) config('media.quota.daily_limit', 10000) - $this->spent($this->globalKey()));
    }

    /**
     * Seconds until the Data API's own reset boundary: midnight Pacific, not
     * UTC and not the app timezone. The wrong hour makes a client retry
     * straight into the same exhausted quota.
     */
    public function secondsUntilReset(): int
    {
        $timezone = (string) config('media.quota.reset_timezone', 'America/Los_Angeles');
        $now = CarbonImmutable::now($timezone);

        return max(1, (int) $now->addDay()->startOfDay()->diffInSeconds($now, absolute: true));
    }

    private function increment(string $key, int $units, int $ttl): int
    {
        // add() is atomic, seeds only when absent, and is what attaches the
        // expiry; increment() alone would leak yesterday's spend into today.
        Cache::add($key, 0, $ttl);

        return (int) Cache::increment($key, $units);
    }

    private function spent(string $key): int
    {
        return (int) (Cache::get($key) ?? 0);
    }

    private function globalKey(): string
    {
        return 'quota:global:'.$this->resetDate();
    }

    private function tenantKey(Tenant $tenant): string
    {
        return "quota:tenant:{$tenant->id}:".$this->resetDate();
    }

    private function resetDate(): string
    {
        $timezone = (string) config('media.quota.reset_timezone', 'America/Los_Angeles');

        return CarbonImmutable::now($timezone)->toDateString();
    }
}
