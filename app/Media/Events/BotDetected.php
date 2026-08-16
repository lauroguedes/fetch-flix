<?php

namespace App\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * YouTube served "Sign in to confirm you're not a bot".
 *
 * Tracked separately from a generic extractor failure because the remedy is
 * different: this one is about where our traffic originates, not about the
 * binary being out of date. The rate of these events is what decides whether to
 * escalate to a PO token provider or move resolver egress off the datacenter.
 */
final class BotDetected
{
    use Dispatchable;

    public function __construct(
        public readonly string $stderr,
    ) {}
}
