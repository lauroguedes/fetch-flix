<?php

namespace App\Media\Discovery;

use App\Media\Data\Dimensions;
use App\Media\Data\VideoCard;

/**
 * Supplies the width and height the Data API does not return.
 *
 * The contract is absolute: a card leaving this class always carries non-zero
 * dimensions. Consumers size a player frame before anything loads and
 * short-form video is 9:16, so a missing value does not degrade gracefully, it
 * renders every portrait clip into a landscape box.
 *
 * Phase 3 implements the terminal rung only: the configured default. Phase 4
 * adds the oEmbed lookup and aspect-ratio snapping in front of it, which is why
 * this is a seam rather than an inline constant.
 */
class DimensionResolver
{
    /**
     * @param  array<int, VideoCard>  $cards
     * @return array<int, VideoCard>
     */
    public function hydrate(array $cards): array
    {
        return array_map(
            fn (VideoCard $card) => $card->width > 0 && $card->height > 0
                ? $card
                : $card->withDimensions($this->resolve($card)),
            $cards
        );
    }

    protected function resolve(VideoCard $card): Dimensions
    {
        return $this->default();
    }

    protected function default(): Dimensions
    {
        /** @var array{width: int, height: int} $default */
        $default = config('media.dimensions.default', ['width' => 1080, 'height' => 1920]);

        return new Dimensions($default['width'], $default['height']);
    }
}
