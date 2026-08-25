<?php

namespace App\Media\Data;

/**
 * One row in a discovery feed: enough to render a card and open a preview.
 */
final readonly class VideoCard
{
    public function __construct(
        public string $id,
        public string $title,
        public string $channel,
        public ?int $durationMs,
        public ?string $thumbnailUrl,
        /**
         * Never null by the time this reaches a client: consumers size a player
         * frame before anything loads, so a missing dimension renders every
         * portrait clip into a landscape box.
         */
        public int $width,
        public int $height,
        /** Which provider this id belongs to, and which URL shape `url` follows. */
        public string $provider = 'youtube',
        /**
         * The canonical page URL, ready to hand back to POST /v1/resolve.
         *
         * Carried on the card rather than derived at render time: the provider
         * that produced the id is the only thing that knows how to turn one
         * back into a URL, and it is out of scope by the time a response is
         * serialized. Built once here, it is also cached with the page.
         */
        public string $url = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'channel' => $this->channel,
            'duration_ms' => $this->durationMs,
            'thumbnail_url' => $this->thumbnailUrl,
            'width' => $this->width,
            'height' => $this->height,
            'provider' => $this->provider,
            'url' => $this->url,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            title: (string) $data['title'],
            channel: (string) $data['channel'],
            durationMs: isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            thumbnailUrl: $data['thumbnail_url'] ?? null,
            width: (int) $data['width'],
            height: (int) $data['height'],
            provider: (string) $data['provider'],
            url: (string) $data['url'],
        );
    }

    public function withDimensions(Dimensions $dimensions): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            channel: $this->channel,
            durationMs: $this->durationMs,
            thumbnailUrl: $this->thumbnailUrl,
            width: $dimensions->width,
            height: $dimensions->height,
            provider: $this->provider,
            url: $this->url,
        );
    }
}
