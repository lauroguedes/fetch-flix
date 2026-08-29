<?php

namespace App\Media;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\Data\ResolvedMedia;

/**
 * Re-applies the caller's ceilings to an envelope that was produced for
 * somebody else.
 *
 * The shared cache and the stored file are cross-tenant on purpose: the same
 * video at the same height is the same extraction whoever asks. The ceilings
 * are not shared. `MediaRequest::fromTenant()` folds the tenant's own policy
 * (`max_duration_ms`, `max_bytes`, `min_progressive_height`) into them, and
 * none of the three is part of `MediaRequest::fingerprint()` — deliberately,
 * since keying the cache on them would fragment it per ceiling and throw away
 * the sharing that makes it worth having.
 *
 * The consequence is that a warm hit reaches a caller whose limits nobody
 * checked. Extraction enforces all three (duration in the provider, bytes and
 * the height floor in `FormatSelector`), so every path that answers *without*
 * extracting has to enforce them here instead.
 */
final class LimitGuard
{
    public static function assert(MediaRequest $request, ResolvedMedia $media): void
    {
        if ($request->maxDurationMs !== null
            && $media->durationMs !== null
            && $media->durationMs > $request->maxDurationMs) {
            throw ApiException::tooLong();
        }

        if ($request->maxBytes !== null
            && $media->media->bytes !== null
            && $media->media->bytes > $request->maxBytes) {
            throw ApiException::tooLarge();
        }

        // Progressive only, matching `FormatSelector`, which applies the floor
        // on its progressive branch and never on the audio-only one. An
        // audio-only stream has no height, so judging it against a video floor
        // would refuse from cache what a fresh extraction returns happily.
        if (! $request->audioOnly
            && $request->minProgressiveHeight > 0
            && ($media->media->height ?? 0) < $request->minProgressiveHeight) {
            throw ApiException::mediaMissing(
                'No progressive stream meets the minimum acceptable quality.'
            );
        }
    }
}
