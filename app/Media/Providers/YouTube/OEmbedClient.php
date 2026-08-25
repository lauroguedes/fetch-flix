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
 * Costs zero Data API quota, which is why it exists: the Data API returns no
 * dimensions and paying 100-unit search calls to learn a shape would be absurd.
 *
 * The /shorts/ form is required for vertical video and is also honest — it
 * reports 16:9 for a landscape video that merely appeared in a shorts feed — so
 * its answer is trusted as returned. See config/media.php for the measurements.
 *
 * The numbers are the embed player box, not the encode, so only the ratio is
 * meaningful; Dimensions::snapToStandardEncode() handles that.
 */
final class OEmbedClient
{
    public function __construct(
        private readonly YouTubeUrlParser $urls,
    ) {}

    /**
     * Look up many videos at once, pooled so a page of fifty costs roughly one
     * round trip rather than fifty.
     *
     * @param  array<int, array{id: string, duration_ms: int|null}>  $videos
     * @return array<string, Dimensions> keyed by video id; a missing entry means
     *                                   the lookup failed and the caller must
     *                                   fall back
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

            // Long videos cannot be Shorts: asking /shorts/ is a wasted trip.
            if ($duration !== null && $duration <= $threshold) {
                $shortsFirst[] = $video['id'];
            } else {
                $watchOnly[] = $video['id'];
            }
        }

        // Both forms go out together: only the /shorts/ retry below depends on
        // a result, so serialising the long videos behind it would pay two
        // round trips for one page.
        $found = $this->fetch(
            array_fill_keys($shortsFirst, true) + array_fill_keys($watchOnly, false)
        );

        // A /shorts/ miss means "not a Short", not "no dimensions".
        $missed = array_values(array_diff($shortsFirst, array_keys($found)));

        return $found + $this->fetch(array_fill_keys($missed, false));
    }

    /**
     * @param  array<string, bool>  $forms  video id => ask the /shorts/ form
     * @return array<string, Dimensions>
     */
    private function fetch(array $forms): array
    {
        if ($forms === []) {
            return [];
        }

        $ids = array_keys($forms);

        $endpoint = (string) config('media.dimensions.oembed_endpoint', 'https://www.youtube.com/oembed');
        $timeout = (int) config('media.dimensions.oembed_timeout', 2);

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $id) => $pool->as($id)->timeout($timeout)->get($endpoint, [
                    'format' => 'json',
                    'url' => $forms[$id]
                        ? $this->urls->shortsUrl($id)
                        : $this->urls->watchUrl($id),
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
