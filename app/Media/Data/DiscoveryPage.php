<?php

namespace App\Media\Data;

final readonly class DiscoveryPage
{
    /**
     * @param  array<int, VideoCard>  $videos
     */
    public function __construct(
        public array $videos,
        public ?string $nextPageToken = null,
        public int $quotaUnitsSpent = 0,
    ) {}

    /**
     * Applied after caching, never before: upstream's `videoDuration=short`
     * only means "under four minutes", far too coarse for a short-form feed.
     */
    public function withinDuration(?int $maxDurationMs): self
    {
        if ($maxDurationMs === null) {
            return $this;
        }

        $videos = array_values(array_filter(
            $this->videos,
            fn (VideoCard $video) => $video->durationMs === null || $video->durationMs <= $maxDurationMs
        ));

        return new self($videos, $this->nextPageToken, $this->quotaUnitsSpent);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'videos' => array_map(fn (VideoCard $v) => $v->toArray(), $this->videos),
            'next_page_token' => $this->nextPageToken,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            videos: array_map(
                fn (array $v) => VideoCard::fromArray($v),
                is_array($data['videos'] ?? null) ? $data['videos'] : []
            ),
            nextPageToken: $data['next_page_token'] ?? null,
        );
    }
}
