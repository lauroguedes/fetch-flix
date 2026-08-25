<?php

namespace App\Api;

/**
 * Per-request accumulator for what an API call cost. Request-scoped rather than
 * threaded through call signatures, because the things that know the cost are
 * several levels below the middleware that reports it.
 */
final class UsageTracker
{
    private int $quotaUnits = 0;

    private bool $cacheHit = true;

    private ?string $provider = null;

    /**
     * Called explicitly rather than relying on a fresh container per request:
     * under Octane a scoped binding would carry one tenant's spend into the
     * next request and bill it to somebody else.
     */
    public function reset(): void
    {
        $this->quotaUnits = 0;
        $this->cacheHit = true;
        $this->provider = null;
    }

    public function spendUnits(int $units): void
    {
        $this->quotaUnits += $units;
    }

    /**
     * A request that reached an upstream API is by definition not a cache hit.
     */
    public function markCacheMiss(string $provider): void
    {
        $this->cacheHit = false;
        $this->provider = $provider;
    }

    public function markCacheHit(string $provider): void
    {
        $this->provider ??= $provider;
    }

    public function quotaUnits(): int
    {
        return $this->quotaUnits;
    }

    public function wasCacheHit(): bool
    {
        return $this->cacheHit;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }
}
