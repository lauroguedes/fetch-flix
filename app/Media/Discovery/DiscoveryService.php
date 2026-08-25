<?php

namespace App\Media\Discovery;

use App\Api\UsageTracker;
use App\Media\Data\DiscoveryPage;
use App\Media\Data\DiscoveryQuery;
use App\Media\Providers\YouTube\DataApiClient;
use App\Models\CachedDiscovery;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Discovery caching.
 *
 * A discovery result is identical for everybody, and search.list costs 100
 * units against a 10,000/day allowance shared by the whole project, so the
 * cache is the feature and a miss is a budget event.
 *
 * Three layers, cheapest first:
 *   1. Redis, via Cache::flexible, so nobody waits on a 100-unit call
 *   2. the cached_discoveries table, so a Redis flush is not a quota event
 *   3. the live API, only when both miss and quota permits
 */
final class DiscoveryService
{
    public function __construct(
        private readonly DataApiClient $api,
        private readonly DimensionResolver $dimensions,
        private readonly UsageTracker $tracker,
    ) {}

    public function discover(Tenant $tenant, DiscoveryQuery $query): DiscoveryPage
    {
        $key = $query->cacheKey();

        $fresh = (int) config('media.discovery.cache_fresh', 21600);
        $stale = (int) config('media.discovery.cache_stale', 86400);

        // Fresh: returns immediately. Between fresh and stale: serves the
        // existing page and refreshes after the response is sent, so no caller
        // pays the API latency.
        $payload = Cache::flexible($key, [$fresh, $stale], function () use ($tenant, $query, $key, $fresh) {
            return $this->fetchThroughDatabase($tenant, $query, $key, $fresh)->toArray();
        });

        $this->tracker->markCacheHit('youtube');

        $page = DiscoveryPage::fromArray($payload);

        // Filtered on the way out, never before caching, so the cached superset
        // serves every max_duration_ms variant across all tenants.
        return $page->withinDuration($query->maxDurationMs);
    }

    /**
     * Layer two and three.
     */
    private function fetchThroughDatabase(Tenant $tenant, DiscoveryQuery $query, string $key, int $fresh): DiscoveryPage
    {
        $row = CachedDiscovery::query()->where('cache_key', $key)->first();

        if ($row !== null && $row->isFresh()) {
            return DiscoveryPage::fromArray($row->payload);
        }

        $this->tracker->markCacheMiss('youtube');

        $page = $this->api->search($tenant, $query);

        // Resolved once before storage, so every later reader gets them free.
        $page = new DiscoveryPage(
            videos: $this->dimensions->hydrate($page->videos),
            nextPageToken: $page->nextPageToken,
            quotaUnitsSpent: $page->quotaUnitsSpent,
        );

        CachedDiscovery::query()->updateOrCreate(
            ['cache_key' => $key],
            [
                'kind' => $query->kind,
                'provider' => 'youtube',
                'payload' => $page->toArray(),
                'next_page_token' => $page->nextPageToken,
                'quota_units_spent' => $page->quotaUnitsSpent,
                'fetched_at' => now(),
                'expires_at' => now()->addSeconds($fresh),
            ]
        );

        return $page;
    }
}
