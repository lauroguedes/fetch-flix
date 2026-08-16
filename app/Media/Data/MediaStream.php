<?php

namespace App\Media\Data;

use Illuminate\Support\Carbon;

/**
 * The single downloadable file handed back to the client.
 */
final readonly class MediaStream
{
    /**
     * @param  array<string, string>  $headers  Passed to the client verbatim. Some CDNs 403
     *                                          without an exact Referer, and the client feeds
     *                                          these straight into URLSession.
     */
    public function __construct(
        public string $url,
        public string $mimeType,
        public string $container,
        public ?int $bytes,
        public ?int $width,
        public ?int $height,
        public bool $hasAudio,
        public bool $hasVideo,
        public array $headers,
        public Carbon $expiresAt,
        public bool $degraded = false,
        public ?int $requestedHeight = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }
}
