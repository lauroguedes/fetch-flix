<?php

namespace App\Media\Data;

final readonly class Dimensions
{
    /**
     * Standard encodes as [minRatio, maxRatio, width, height].
     */
    private const STANDARD = [
        [0.50, 0.60, 1080, 1920],  // 9:16 vertical
        [0.70, 0.80, 1080, 1440],  // 3:4 portrait
        [0.95, 1.05, 1080, 1080],  // 1:1 square
        [1.20, 1.40, 1440, 1080],  // 4:3 landscape
        [1.70, 1.80, 1280, 720],   // 16:9 landscape
        [2.30, 2.45, 1280, 544],   // 21:9 cinematic
    ];

    /** Long edge used when a ratio matches nothing standard. */
    private const LONG_EDGE = 1280;

    public function __construct(
        public int $width,
        public int $height,
    ) {}

    private function aspectRatio(): float
    {
        return $this->height === 0 ? 0.0 : $this->width / $this->height;
    }

    /**
     * Snap a measured ratio onto plausible encode dimensions.
     *
     * oEmbed reports the embed player box (200x113, 113x200), not the encode,
     * so only the ratio is meaningful. Snapping matters because clients compare
     * dimensions to pick a layout: 1080x1920 reads as a vertical short,
     * 113x200 reads as a thumbnail.
     */
    public function snapToStandardEncode(): self
    {
        $ratio = $this->aspectRatio();

        if ($ratio <= 0.0) {
            return $this;
        }

        foreach (self::STANDARD as [$min, $max, $width, $height]) {
            if ($ratio >= $min && $ratio <= $max) {
                return new self($width, $height);
            }
        }

        // Unusual but real. Preserve the measured ratio rather than forcing a
        // standard one, which would report a shape the video does not have.
        return $ratio >= 1.0
            ? new self(self::LONG_EDGE, max(1, (int) round(self::LONG_EDGE / $ratio)))
            : new self(max(1, (int) round(self::LONG_EDGE * $ratio)), self::LONG_EDGE);
    }
}
