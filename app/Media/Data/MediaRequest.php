<?php

namespace App\Media\Data;

use App\Models\Tenant;

/**
 * A single resolve request, already validated and tenant-scoped.
 */
final readonly class MediaRequest
{
    public function __construct(
        public string $url,
        public int $maxHeight = 720,
        public bool $audioOnly = false,
        public ?int $maxDurationMs = null,
        public ?int $maxBytes = null,
        public int $minProgressiveHeight = 0,
    ) {}

    public static function fromTenant(
        Tenant $tenant,
        string $url,
        int $maxHeight,
        bool $audioOnly,
        ?int $maxDurationMs,
    ): self {
        // Both are upper bounds, so the tighter wins: a generous request body
        // cannot talk a tenant past its own configured maximum.
        $duration = self::tightest($maxDurationMs, $tenant->max_duration_ms);

        return new self(
            url: $url,
            maxHeight: $maxHeight,
            audioOnly: $audioOnly,
            maxDurationMs: $duration,
            maxBytes: $tenant->max_bytes,
            minProgressiveHeight: $tenant->min_progressive_height,
        );
    }

    private static function tightest(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return min($a, $b);
    }

    /**
     * Identifies the *work*, not the caller: two tenants asking for the same
     * video at the same height want the same extraction, which is what the
     * single-flight lock keys on.
     */
    public function fingerprint(string $provider, string $providerId): string
    {
        return hash('sha256', implode('|', [
            $provider,
            $providerId,
            $this->maxHeight,
            $this->audioOnly ? '1' : '0',
            'progressive',
        ]));
    }
}
