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
         * Never null by the time this reaches a client. Consumers size a player
         * frame before anything loads, and short-form video is 9:16, so a
         * missing dimension renders every portrait clip into a landscape box.
         */
        public int $width,
        public int $height,
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
        );
    }
}
