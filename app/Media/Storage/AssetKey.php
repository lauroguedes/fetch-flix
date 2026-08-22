<?php

namespace App\Media\Storage;

use InvalidArgumentException;

/**
 * Identifies a stored variant from the request alone.
 *
 * Derived from what the caller asked for rather than from what the extractor
 * eventually chose, which is the whole point: a resolve can look this up
 * *before* spawning yt-dlp, and on a hit skip extraction entirely. That turns a
 * repeat resolve from an 8s process into a database read.
 *
 * It is also the value that appears in the signed delivery URL, so it must be
 * opaque and unguessable-in-bulk: a plain video id would let anyone enumerate
 * what this deployment has cached.
 */
final readonly class AssetKey
{
    private function __construct(
        public string $value,
    ) {}

    public static function for(
        string $provider,
        string $providerVideoId,
        int $maxHeight,
        bool $audioOnly,
    ): self {
        return new self(hash('sha256', implode('|', [
            $provider,
            $providerVideoId,
            $maxHeight,
            $audioOnly ? '1' : '0',
        ])));
    }

    /**
     * Rebuild from a URL segment.
     *
     * Validated rather than trusted: this value arrives from the network and is
     * used in database lookups, so anything but 64 lowercase hex characters is
     * rejected before it travels any further.
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('Malformed asset key.');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
