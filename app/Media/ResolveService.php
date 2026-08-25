<?php

namespace App\Media;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\Data\ResolvedMedia;
use App\Media\Jobs\ResolveMediaJob;
use App\Media\Storage\AssetStore;
use App\Models\Tenant;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrates a single synchronous resolve.
 *
 * Synchronous by design: the client is built against a 200-with-media response
 * and has no polling branch. Everything here is about staying inside its 12s
 * ceiling and making a miss cheap rather than fatal.
 */
final class ResolveService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly AssetStore $assets,
    ) {}

    public function resolve(Tenant $tenant, MediaRequest $request, string $requestId): ResolvedMedia
    {
        $dedupeKey = $this->dedupeKey($tenant, $requestId);

        // Clients reuse X-Request-Id across retries, so replaying the stored
        // envelope saves a second expensive extraction for one user action.
        if ($replay = $this->readCached($dedupeKey)) {
            return $replay;
        }

        $provider = $this->registry->forUrl($request->url);
        $providerId = $provider->normalizeId($request->url);
        $fingerprint = $request->fingerprint($provider->slug(), $providerId);
        $sharedKey = self::sharedKey($fingerprint);

        // Shared across tenants: the same video at the same height is the same
        // extraction no matter who asks.
        if ($shared = $this->readCached($sharedKey)) {
            $this->remember($dedupeKey, $shared, $this->dedupeTtl($shared));

            return $shared;
        }

        // Behind the cache but ahead of extraction: a file already on our disk
        // turns an 8s process spawn into an indexed read. Survives a cache
        // flush, which the two lookups above do not.
        if ($stored = $this->assets->cached($provider->slug(), $providerId, $request)) {
            $this->remember($sharedKey, $stored, $this->sharedTtl($stored));
            $this->remember($dedupeKey, $stored, $this->dedupeTtl($stored));

            return $stored;
        }

        $lock = Cache::lock($this->lockKey($fingerprint), (int) config('media.resolve.lock_ttl', 15));

        try {
            $lock->block((int) config('media.resolve.lock_wait', 3));
        } catch (LockTimeoutException) {
            // Another request is already extracting this video. Waiting longer
            // would blow the client's ceiling; by the time it retries the
            // in-flight extraction has populated the shared cache.
            throw ApiException::resolverUnavailable(
                retryAfter: 2,
                message: 'This media is already being resolved. Please retry shortly.',
                context: ['reason' => 'lock_contention'],
            );
        }

        try {
            // The holder of the lock may have finished while we were blocked.
            if ($shared = $this->readCached($sharedKey)) {
                $this->remember($dedupeKey, $shared, $this->dedupeTtl($shared));

                return $shared;
            }

            try {
                $resolved = $this->extractAndCache($request, $fingerprint, $provider);
            } catch (ApiException $e) {
                $this->handleTimeout($e, $request, $fingerprint);

                throw $e;
            }

            $this->remember($dedupeKey, $resolved, $this->dedupeTtl($resolved));

            return $resolved;
        } finally {
            $lock->release();
        }
    }

    /**
     * Extract, prepare for delivery, and write the shared cache entry.
     *
     * Public for the queued fallback, which has to produce the same envelope
     * the synchronous path does: caching the provider's own result instead
     * would hand a later caller an IP-bound URL that answers 403.
     *
     * @param  Provider|null  $provider  Passed by the synchronous path, which
     *                                   already matched the URL; the queued
     *                                   fallback starts from nothing and looks
     *                                   it up here.
     */
    public function extractAndCache(MediaRequest $request, string $fingerprint, ?Provider $provider = null): ResolvedMedia
    {
        $provider ??= $this->registry->forUrl($request->url);
        $resolved = $this->assets->prepare($provider->resolve($request), $request);

        $this->remember(self::sharedKey($fingerprint), $resolved, $this->sharedTtl($resolved));

        return $resolved;
    }

    /**
     * A deadline miss must not throw the work away: the extraction is re-run on
     * a queue so the client's retry hits a warm cache. Invisible to the
     * contract.
     */
    private function handleTimeout(ApiException $e, MediaRequest $request, string $fingerprint): void
    {
        if (($e->context['reason'] ?? null) !== 'timeout') {
            return;
        }

        ResolveMediaJob::dispatch($request, $fingerprint);
    }

    private function readCached(string $key): ?ResolvedMedia
    {
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            return null;
        }

        $resolved = ResolvedMedia::fromArray($payload);

        // An expired entry is worthless: handing it back surfaces as an
        // unexplained download failure.
        if ($resolved->media->isExpired()) {
            Cache::forget($key);

            return null;
        }

        return $resolved;
    }

    private function remember(string $key, ResolvedMedia $media, int $ttl): void
    {
        if ($ttl > 0) {
            Cache::put($key, $media->toArray(), $ttl);
        }
    }

    /**
     * Never outlives the URL itself, and never exceeds the ceiling: a cached
     * entry whose URL has expired surfaces as an unexplained download failure.
     */
    private function sharedTtl(ResolvedMedia $media): int
    {
        $untilExpiry = (int) floor(now()->diffInSeconds($media->media->expiresAt, false)) - 30;

        return max(0, min($untilExpiry, 900));
    }

    private function dedupeTtl(ResolvedMedia $media): int
    {
        $configured = (int) config('media.resolve.dedupe_ttl', 300);

        return max(0, min($configured, $this->sharedTtl($media)));
    }

    private function dedupeKey(Tenant $tenant, string $requestId): string
    {
        // Tenant-scoped: one consumer must never observe another's request id.
        return "resolve:req:{$tenant->id}:".sha1($requestId);
    }

    /** Shared across tenants: same video, same height, same extraction. */
    private static function sharedKey(string $fingerprint): string
    {
        return "resolve:media:{$fingerprint}";
    }

    private function lockKey(string $fingerprint): string
    {
        return "resolve:lock:{$fingerprint}";
    }
}
