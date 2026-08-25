<?php

namespace App\Media\YtDlp;

/**
 * The parsed info dict from `yt-dlp --dump-single-json`.
 */
final readonly class YtDlpResult
{
    /**
     * @param  array<string, mixed>  $info
     */
    public function __construct(
        public array $info,
        public int $elapsedMs = 0,
    ) {}

    public function id(): ?string
    {
        return is_string($this->info['id'] ?? null) ? $this->info['id'] : null;
    }

    public function title(): string
    {
        return is_string($this->info['title'] ?? null) ? $this->info['title'] : 'Untitled';
    }

    public function author(): ?string
    {
        foreach (['uploader', 'channel', 'creator'] as $key) {
            if (is_string($this->info[$key] ?? null) && $this->info[$key] !== '') {
                return $this->info[$key];
            }
        }

        return null;
    }

    /**
     * yt-dlp reports duration in (possibly fractional) seconds.
     */
    public function durationMs(): ?int
    {
        $duration = $this->info['duration'] ?? null;

        return is_numeric($duration) ? (int) round($duration * 1000) : null;
    }

    public function thumbnailUrl(): ?string
    {
        return is_string($this->info['thumbnail'] ?? null) ? $this->info['thumbnail'] : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formats(): array
    {
        $formats = $this->info['formats'] ?? [];

        return is_array($formats) ? array_values(array_filter($formats, 'is_array')) : [];
    }
}
