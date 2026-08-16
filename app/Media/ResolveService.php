<?php

namespace App\Media;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\Data\ResolvedMedia;
use App\Media\Jobs\ResolveMediaJob;
use App\Models\Tenant;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrates a single synchronous resolve.
 *
 * Resolution is synchronous by design. The client is already built against a
 * 200-with-media response and has no polling branch, so turning this into
 * accept-then-poll would break the consumer that already exists. Everything
 * here is about staying inside its 12s ceiling and making a miss cheap rather
 * than fatal.
 */
final class ResolveService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {}

    public function resolve(Tenant $tenant, MediaRequest $request, string $requestId): ResolvedMedia
    {
        $dedupeKey = $this->dedupeKey($tenant, $requestId);

        // Clients reuse X-Request-Id across their own retries. Replaying the
        // stored envelope is what makes the retry after a timeout cheap instead
        // of a second expensive extraction for one user action.
        if ($replay = $this->readCached($dedupeKey)) {
            return $replay;
        }

        $provider = $this->registry->resolverFor($request->url);
        $providerId = $provider->normalizeId($request->url);
        $fingerprint = $request->fingerprint($provider->slug(), $providerId);
        $sharedKey = $this->sharedKey($fingerprint);

        // Shared across tenants: the same video at the same height is the same
        // extraction no matter who asks.
        if ($shared = $this->readCached($sharedKey)) {
            $this->remember($dedupeKey, $shared, $this->dedupeTtl($shared));

            return $shared;
        }

        $lock = Cache::lock($this->lockKey($fingerprint), (int) config('media.resolve.lock_ttl', 15));

        try {
            $lock->block((int) config('media.resolve.lock_wait', 3));
        } catch (LockTimeoutException) {
            // Another request is already extracting this exact video. Waiting
            // longer would push us past the client's ceiling, so tell it to
            // retry: by then the in-flight extraction will have populated the
            // shared cache and the retry returns immediately.
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
                $resolved = $provider->resolve($request);
            } catch (ApiException $e) {
                $this->handleTimeout($e, $request, $fingerprint);

                throw $e;
            }

            $ttl = $this->sharedTtl($resolved);

            if ($ttl > 0) {
                $this->remember($sharedKey, $resolved, $ttl);
            }

            $this->remember($dedupeKey, $resolved, $this->dedupeTtl($resolved));

            return $resolved;
        } finally {
            $lock->release();
        }
    }

    /**
     * A deadline miss must not throw the work away.
     *
     * The extraction is re-run on a queue and its result written to the shared
     * cache, so the client's retry a couple of seconds later hits a warm cache
     * and returns immediately. This is the async escape hatch, and it is
     * invisible to the contract.
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

        // A cached entry whose URL has expired is worthless: handing it back
        // would surface to the user as an unexplained download failure.
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
     * Never outlive the URL itself, and never exceed the configured ceiling.
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

    private function sharedKey(string $fingerprint): string
    {
        return "resolve:media:{$fingerprint}";
    }

    private function lockKey(string $fingerprint): string
    {
        return "resolve:lock:{$fingerprint}";
    }
}
