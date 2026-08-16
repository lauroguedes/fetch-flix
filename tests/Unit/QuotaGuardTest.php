<?php

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Media\Discovery\QuotaGuard;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function guard(): QuotaGuard
{
    return app(QuotaGuard::class);
}

function unsavedTenant(int $daily = 2000, int $id = 1): Tenant
{
    $tenant = new Tenant(['quota_units_daily' => $daily]);
    $tenant->id = $id;

    return $tenant;
}

beforeEach(function () {
    Cache::clear();
});

it('lets spend through while budget remains', function () {
    $spent = guard()->spend(unsavedTenant(), 100, fn () => 'called');

    expect($spent)->toBe('called')
        ->and(guard()->remainingForTenant(unsavedTenant()))->toBe(1900);
});

it('refuses the call before making it once the tenant budget is gone', function () {
    $tenant = unsavedTenant(daily: 150);
    $called = 0;

    guard()->spend($tenant, 100, function () use (&$called) {
        $called++;

        return null;
    });

    try {
        guard()->spend($tenant, 100, function () use (&$called) {
            $called++;

            return null;
        });
        test()->fail('Expected rate_limited.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe(ErrorCode::RateLimited)
            // Reserving before the call is what stops a burst slipping through
            // together; the second call must never reach Google.
            ->and($called)->toBe(1)
            ->and($e->headers['X-Quota-Scope'])->toBe('tenant');
    }
});

it('distinguishes global exhaustion from tenant exhaustion', function () {
    config()->set('media.quota.daily_limit', 100);

    guard()->spend(unsavedTenant(), 100, fn () => null);

    try {
        // A different tenant with plenty of its own budget still cannot spend
        // what the project as a whole no longer has.
        guard()->spend(unsavedTenant(id: 2), 100, fn () => null);
        test()->fail('Expected rate_limited.');
    } catch (ApiException $e) {
        expect($e->headers['X-Quota-Scope'])->toBe('global');
    }
});

it('reports exhaustion as retryable rather than as a fault', function () {
    config()->set('media.quota.daily_limit', 10);

    try {
        guard()->spend(unsavedTenant(), 100, fn () => null);
        test()->fail('Expected rate_limited.');
    } catch (ApiException $e) {
        // "Out of quota today" is a wait, not a breakage, and the two are
        // different screens for a consumer.
        expect($e->errorCode->httpStatus())->toBe(429)
            ->and($e->errorCode->isRetryable())->toBeTrue()
            ->and($e->retryAfter)->toBeGreaterThan(0);
    }
});

it('counts spend against the Data API reset boundary, not UTC', function () {
    // 05:00 UTC on 2026-08-17 is 22:00 on 2026-08-16 in Pacific time, which is
    // the day Google is billing against. Keying on the UTC date would roll the
    // counter over while Google's day still has hours left, letting the real
    // allowance be overspent every evening.
    CarbonImmutable::setTestNow('2026-08-17T05:00:00Z');

    guard()->spend(unsavedTenant(), 100, fn () => null);

    expect(Cache::get('quota:tenant:1:2026-08-16'))->toBe(100)
        ->and(Cache::get('quota:tenant:1:2026-08-17'))->toBeNull();

    CarbonImmutable::setTestNow();
});

it('advises retrying after the pacific midnight reset', function () {
    CarbonImmutable::setTestNow('2026-08-16T20:00:00Z'); // 13:00 Pacific

    // 11 hours until Pacific midnight.
    expect(guard()->secondsUntilReset())->toBe(11 * 3600);

    CarbonImmutable::setTestNow();
});

it('keeps the counter alive only until the reset', function () {
    guard()->spend(unsavedTenant(), 100, fn () => null);

    // Without a TTL attached at creation, yesterday's spend would leak into
    // today and permanently understate the available budget.
    expect(Cache::get('quota:global:'.CarbonImmutable::now('America/Los_Angeles')->toDateString()))
        ->toBe(100);
});

it('does not refund a failed call', function () {
    $tenant = unsavedTenant();

    try {
        guard()->spend($tenant, 100, fn () => throw new RuntimeException('upstream blew up'));
    } catch (RuntimeException) {
        // Google charges for failed requests too, so a failing loop must still
        // exhaust our counters rather than spinning for free.
    }

    expect(guard()->remainingForTenant($tenant))->toBe(1900);
});
