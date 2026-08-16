<?php

namespace App\Media\Providers\YouTube;

use App\Media\Data\Dimensions;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Aspect ratios from YouTube's oEmbed endpoint.
 *
 * Costs zero Data API quota, which is the entire reason it exists: the Data API
 * returns no dimensions at all, and paying 100-unit search calls to learn a
 * video's shape would be absurd.
 *
 * Verified against live YouTube on 2026-08-16:
 *
 *   9FDVvWR91ww  /watch?v=  -> 200x113   (1.77)
 *   9FDVvWR91ww  /shorts/   -> 113x200   (0.565)
 *   OzK3PNF0WqU  /watch?v=  -> 200x113   (1.77)
 *   OzK3PNF0WqU  /shorts/   -> 200x113   (1.77)
 *
 * So the /shorts/ form is required for vertical video, and it is also honest:
 * it reports 16:9 for a landscape video that merely appeared in a shorts feed.
 * The answer is therefore trusted exactly as returned, never overridden by a
 * "this is short so it must be portrait" assumption.
 *
 * The numbers are the embed player box rather than the encode, so only the
 * ratio is meaningful. Snapping to a nominal encode happens in AspectRatio.
 */
final class OEmbedClient
{
    /**
     * Look up many videos at once.
     *
     * Pooled so a page of fifty costs roughly one round trip rather than fifty,
     * which is what keeps this inside the discover latency budget.
     *
     * @param  array<int, array{id: string, duration_ms: int|null}>  $videos
     * @return array<string, Dimensions> keyed by video id; missing entries mean the
     *                                   lookup failed and a caller must fall back
     */
    public function lookup(array $videos): array
    {
        if ($videos === []) {
            return [];
        }

        $threshold = (int) config('media.dimensions.shorts_duration_threshold_ms', 180000);

        $shortsFirst = [];
        $watchOnly = [];

        foreach ($videos as $video) {
            $duration = $video['duration_ms'];

            // Long videos cannot be Shorts, so asking the /shorts/ endpoint for
            // them is a guaranteed miss and a wasted round trip.
            if ($duration !== null && $duration <= $threshold) {
                $shortsFirst[] = $video['id'];
            } else {
                $watchOnly[] = $video['id'];
            }
        }

        $found = $this->fetch($shortsFirst, shorts: true);

        // A /shorts/ miss means the video is not a Short, not that it has no
        // dimensions, so those fall through to the standard form.
        $missed = array_values(array_diff($shortsFirst, array_keys($found)));

        return $found + $this->fetch([...$watchOnly, ...$missed], shorts: false);
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, Dimensions>
     */
    private function fetch(array $ids, bool $shorts): array
    {
        if ($ids === []) {
            return [];
        }

        $endpoint = (string) config('media.dimensions.oembed_endpoint', 'https://www.youtube.com/oembed');
        $timeout = (int) config('media.dimensions.oembed_timeout', 2);

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $id) => $pool->as($id)->timeout($timeout)->get($endpoint, [
                    'format' => 'json',
                    'url' => $shorts
                        ? "https://www.youtube.com/shorts/{$id}"
                        : "https://www.youtube.com/watch?v={$id}",
                ]),
                $ids
            ));
        } catch (Throwable) {
            // Dimensions are a nice-to-have on a path that must still return a
            // feed. A total oEmbed outage degrades to the fallback chain rather
            // than failing the request.
            return [];
        }

        $dimensions = [];

        foreach ($ids as $id) {
            $response = $responses[$id] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $width = $response->json('width');
            $height = $response->json('height');

            if (is_int($width) && is_int($height) && $width > 0 && $height > 0) {
                $dimensions[$id] = new Dimensions($width, $height);
            }
        }

        return $dimensions;
    }
}
