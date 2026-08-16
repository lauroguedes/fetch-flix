<?php

namespace App\Console\Commands\Media;

use App\Api\Exceptions\ApiException;
use App\Media\Data\DiscoveryQuery;
use App\Media\Discovery\DiscoveryService;
use App\Models\Tenant;
use App\Models\VideoCategory;
use App\Tenancy\TenantStatus;
use Illuminate\Console\Command;

/**
 * Refreshes category feeds ahead of demand.
 *
 * Category traffic is the predictable part of discovery, so paying for it on a
 * schedule from a reserved budget is far cheaper than paying for it on a cold
 * cache during peak. Without this, a user request is what triggers a 100-unit
 * call, and the daily allowance drains unpredictably.
 */
class WarmDiscoveryCacheCommand extends Command
{
    protected $signature = 'discover:warm {--category=* : Limit to specific slugs}';

    protected $description = 'Pre-fetch category discovery feeds from the reserved quota budget';

    public function handle(DiscoveryService $discovery): int
    {
        $tenant = $this->warmingTenant();

        $categories = VideoCategory::query()
            ->active()
            ->when($this->option('category'), fn ($q, $slugs) => $q->whereIn('slug', $slugs))
            ->get();

        if ($categories->isEmpty()) {
            $this->components->warn('No active categories to warm.');

            return self::SUCCESS;
        }

        $warmed = 0;
        $failed = 0;

        foreach ($categories as $category) {
            try {
                $page = $discovery->discover($tenant, DiscoveryQuery::category($category, null, null));

                $warmed++;
                $this->components->twoColumnDetail($category->slug, count($page->videos).' videos');
            } catch (ApiException $e) {
                $failed++;
                $this->components->twoColumnDetail($category->slug, "<fg=red>{$e->errorCode->value}</>");

                // Exhausting the budget mid-run is expected near the limit and
                // is not worth burning the remaining categories against.
                if ($e->errorCode->value === 'rate_limited') {
                    $this->components->warn('Quota exhausted; stopping.');
                    break;
                }
            }
        }

        $this->components->info("Warmed {$warmed} categories, {$failed} failed.");

        return $failed > 0 && $warmed === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Warming spends from a dedicated tenant rather than a real consumer's
     * allowance, so scheduled maintenance can never eat a paying tenant's
     * budget.
     */
    private function warmingTenant(): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['slug' => 'system-warmer'],
            [
                'name' => 'Scheduled Cache Warming',
                'status' => TenantStatus::Active,
                'quota_units_daily' => (int) config('media.quota.reserved_for_warming', 4000),
            ]
        );
    }
}
