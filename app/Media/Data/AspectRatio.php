<?php

namespace App\Media\Data;

/**
 * Turns a measured aspect ratio into plausible encode dimensions.
 *
 * oEmbed reports the embed player box (200x113, 113x200), not the encode, so
 * the numbers themselves are useless to a client sizing a video frame. Only the
 * ratio survives, and it is snapped to the standard encode that ratio implies.
 *
 * Snapping rather than scaling arbitrarily matters because clients compare
 * dimensions to pick a layout: 1080x1920 is recognisably a vertical short,
 * whereas 113x200 looks like a thumbnail.
 */
final class AspectRatio
{
    /**
     * Standard encodes, as ratio => [width, height].
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

    public static function snap(Dimensions $measured): Dimensions
    {
        $ratio = $measured->aspectRatio();

        if ($ratio <= 0.0) {
            return $measured;
        }

        foreach (self::STANDARD as [$min, $max, $width, $height]) {
            if ($ratio >= $min && $ratio <= $max) {
                return new Dimensions($width, $height);
            }
        }

        // Unusual but real. Preserve the measured ratio at a sensible scale
        // rather than forcing it into the nearest standard, which would tell
        // the client a shape the video does not have.
        return $ratio >= 1.0
            ? new Dimensions(self::LONG_EDGE, max(1, (int) round(self::LONG_EDGE / $ratio)))
            : new Dimensions(max(1, (int) round(self::LONG_EDGE * $ratio)), self::LONG_EDGE);
    }
}
