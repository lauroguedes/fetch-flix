<?php

namespace App\Media\Data;

final readonly class Dimensions
{
    public function __construct(
        public int $width,
        public int $height,
    ) {}

    public function isPortrait(): bool
    {
        return $this->height > $this->width;
    }

    public function aspectRatio(): float
    {
        return $this->height === 0 ? 0.0 : $this->width / $this->height;
    }
}
