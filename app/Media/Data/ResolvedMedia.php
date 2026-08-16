<?php

namespace App\Media\Data;

use Illuminate\Support\Carbon;

final readonly class ResolvedMedia
{
    public function __construct(
        public string $provider,
        public string $id,
        public string $title,
        public ?string $author,
        public ?int $durationMs,
        public ?string $thumbnailUrl,
        public MediaStream $media,
    ) {}

    /**
     * Serialised form used for caching and request-id replay.
     *
     * Deliberately hand-rolled rather than serialize(): the cached payload
     * outlives a deploy, and a changed class shape must not produce an
     * unreadable cache entry mid-rollout.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'duration_ms' => $this->durationMs,
            'thumbnail_url' => $this->thumbnailUrl,
            'media' => [
                'url' => $this->media->url,
                'mime_type' => $this->media->mimeType,
                'container' => $this->media->container,
                'bytes' => $this->media->bytes,
                'width' => $this->media->width,
                'height' => $this->media->height,
                'has_audio' => $this->media->hasAudio,
                'has_video' => $this->media->hasVideo,
                'headers' => $this->media->headers,
                'expires_at' => $this->media->expiresAt->toIso8601ZuluString(),
                'degraded' => $this->media->degraded,
                'requested_height' => $this->media->requestedHeight,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $media = $data['media'];

        return new self(
            provider: $data['provider'],
            id: $data['id'],
            title: $data['title'],
            author: $data['author'],
            durationMs: $data['duration_ms'],
            thumbnailUrl: $data['thumbnail_url'],
            media: new MediaStream(
                url: $media['url'],
                mimeType: $media['mime_type'],
                container: $media['container'],
                bytes: $media['bytes'],
                width: $media['width'],
                height: $media['height'],
                hasAudio: $media['has_audio'],
                hasVideo: $media['has_video'],
                headers: $media['headers'],
                expiresAt: Carbon::parse($media['expires_at']),
                degraded: $media['degraded'] ?? false,
                requestedHeight: $media['requested_height'] ?? null,
            ),
        );
    }
}
