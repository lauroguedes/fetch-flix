<?php

namespace App\Media\Data;

/**
 * Which rung of the fallback chain produced a set of dimensions, so the share
 * of guessed values is measurable: a feed served entirely from `Default` looks
 * identical to one from `OEmbed` until someone notices every clip is misshaped.
 */
enum DimensionSource: string
{
    /** Reported by oEmbed and trusted as returned. */
    case OEmbed = 'oembed';

    /** oEmbed failed; inferred from duration and the shorts URL form. */
    case Heuristic = 'heuristic';

    /**
     * Nothing was knowable. Retained for rows written before `guess()` started
     * tagging its own fallback as Heuristic; no code path writes it now.
     */
    case Default = 'default';
}
