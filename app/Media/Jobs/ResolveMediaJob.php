<?php

namespace App\Media\Jobs;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\ProviderRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Completes an extraction that missed its synchronous deadline.
 *
 * The client is already retrying; this exists so that retry lands on a warm
 * cache instead of triggering the same slow extraction again.
 */
final class ResolveMediaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        private readonly MediaRequest $request,
        private readonly string $fingerprint,
    ) {
        $this->onQueue('resolve');
    }

    /**
     * Keyed on the work, not the caller, so a retry storm from several clients
     * for the same video cannot fan out into duplicate extractions.
     */
    public function uniqueId(): string
    {
        return $this->fingerprint;
    }

    public function uniqueFor(): int
    {
        return 120;
    }

    public function handle(ProviderRegistry $registry): void
    {
        try {
            $provider = $registry->resolverFor($this->request->url);
            $resolved = $provider->resolve($this->request);
        } catch (ApiException) {
            // The synchronous path already returned a correct error to the
            // client. Re-reporting it here would double-count the failure
            // without telling anyone anything new.
            return;
        }

        $untilExpiry = (int) floor(now()->diffInSeconds($resolved->media->expiresAt, false)) - 30;
        $ttl = max(0, min($untilExpiry, 900));

        if ($ttl > 0) {
            Cache::put("resolve:media:{$this->fingerprint}", $resolved->toArray(), $ttl);
        }
    }
}
