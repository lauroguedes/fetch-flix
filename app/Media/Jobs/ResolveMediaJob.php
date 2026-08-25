<?php

namespace App\Media\Jobs;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\ResolveService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    /** Keyed on the work, so a retry storm cannot fan out into duplicates. */
    public function uniqueId(): string
    {
        return $this->fingerprint;
    }

    public function uniqueFor(): int
    {
        return 120;
    }

    public function handle(ResolveService $resolver): void
    {
        try {
            $resolver->extractAndCache($this->request, $this->fingerprint);
        } catch (ApiException) {
            // The synchronous path already returned a correct error to the
            // client; re-reporting would only double-count it.
        }
    }
}
