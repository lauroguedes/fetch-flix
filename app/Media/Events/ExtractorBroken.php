<?php

namespace App\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The extractor failed in a way that suggests the upstream site changed.
 *
 * An event rather than a log line, so alerting thresholds live with the
 * listener instead of being buried in a grep over application logs.
 */
final class ExtractorBroken
{
    use Dispatchable;

    public function __construct(
        public readonly string $stderr,
        /** False when nothing in the pattern table matched, which is the strongest signal. */
        public readonly bool $matched = false,
    ) {}
}
