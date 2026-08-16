<?php

namespace App\Media\Data;

/**
 * Which rung of the fallback chain produced a set of dimensions.
 *
 * Recorded so the share of guessed values is measurable. A feed quietly served
 * entirely from `Default` looks identical to one served from `OEmbed` until
 * someone notices every clip is the wrong shape.
 */
enum DimensionSource: string
{
    /** Reported by oEmbed and trusted as returned. */
    case OEmbed = 'oembed';

    /** oEmbed failed; inferred from duration and the shorts URL form. */
    case Heuristic = 'heuristic';

    /** Nothing was knowable. Configured default. */
    case Default = 'default';

    public function isExact(): bool
    {
        return $this === self::OEmbed;
    }
}
