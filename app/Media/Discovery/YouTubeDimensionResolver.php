<?php

namespace App\Media\Discovery;

use App\Media\Data\AspectRatio;
use App\Media\Data\Dimensions;
use App\Media\Data\DimensionSource;
use App\Media\Data\VideoCard;
use App\Media\Providers\YouTube\OEmbedClient;
use App\Models\VideoDimension;
use Throwable;

/**
 * The full dimension fallback chain.
 *
 * The Data API returns no width or height, so this is where they come from. The
 * contract is absolute: a card leaving here always carries non-zero dimensions,
 * because consumers size a player frame before anything loads and a missing
 * value renders every portrait clip into a landscape box.
 *
 * Rungs, in order:
 *   1. video_dimensions   permanent, cross-tenant, free
 *   2. oEmbed             zero API quota, exact ratio, one pooled round trip
 *   3. heuristic          only when oEmbed fails outright, never as an override
 *   4. configured default terminal, so the chain cannot return nothing
 */
final class YouTubeDimensionResolver extends DimensionResolver
{
    private const PROVIDER = 'youtube';

    public function __construct(
        private readonly OEmbedClient $oembed,
    ) {}

    /**
     * @param  array<int, VideoCard>  $cards
     * @return array<int, VideoCard>
     */
    public function hydrate(array $cards): array
    {
        if ($cards === []) {
            return [];
        }

        // A card that already carries dimensions has them from a source closer
        // to the media than we are, so it is left alone.
        $needing = array_values(array_filter($cards, fn (VideoCard $c) => $c->width <= 0 || $c->height <= 0));

        if ($needing === []) {
            return $cards;
        }

        $known = $this->fromDatabase(array_map(fn (VideoCard $c) => $c->id, $needing));

        $unknown = array_values(array_filter($needing, fn (VideoCard $c) => ! isset($known[$c->id])));

        $resolved = $unknown === [] ? [] : $this->fromOEmbed($unknown);

        $persist = [];
        $out = [];

        foreach ($cards as $card) {
            if ($card->width > 0 && $card->height > 0) {
                $out[] = $card;

                continue;
            }

            if (isset($known[$card->id])) {
                $out[] = $card->withDimensions($known[$card->id]);

                continue;
            }

            [$dimensions, $source] = $resolved[$card->id]
                ?? [$this->guess($card), DimensionSource::Heuristic];

            $persist[] = [
                'provider' => self::PROVIDER,
                'provider_video_id' => $card->id,
                'width' => $dimensions->width,
                'height' => $dimensions->height,
                'source' => $source->value,
                'created_at' => now(),
            ];

            $out[] = $card->withDimensions($dimensions);
        }

        $this->remember($persist);

        return $out;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, Dimensions>
     */
    private function fromDatabase(array $ids): array
    {
        $rows = VideoDimension::query()
            ->where('provider', self::PROVIDER)
            ->whereIn('provider_video_id', $ids)
            ->get();

        $known = [];

        foreach ($rows as $row) {
            // A previously guessed value is not worth keeping: re-asking oEmbed
            // costs nothing and may now succeed. Only exact answers are reused.
            if ($row->source === DimensionSource::OEmbed) {
                $known[$row->provider_video_id] = new Dimensions($row->width, $row->height);
            }
        }

        return $known;
    }

    /**
     * @param  array<int, VideoCard>  $cards
     * @return array<string, array{0: Dimensions, 1: DimensionSource}>
     */
    private function fromOEmbed(array $cards): array
    {
        $measured = $this->oembed->lookup(array_map(
            fn (VideoCard $c) => ['id' => $c->id, 'duration_ms' => $c->durationMs],
            $cards
        ));

        $resolved = [];

        foreach ($measured as $id => $dimensions) {
            // Trusted exactly as returned. oEmbed reports 16:9 for a landscape
            // video even via the /shorts/ form, and overriding that would
            // corrupt landscape videos sitting in a short-form feed.
            $resolved[$id] = [AspectRatio::snap($dimensions), DimensionSource::OEmbed];
        }

        return $resolved;
    }

    /**
     * Only reached when oEmbed gave no answer at all.
     */
    private function guess(VideoCard $card): Dimensions
    {
        $threshold = (int) config('media.dimensions.shorts_duration_threshold_ms', 180000);

        // Short enough to be a Short, and we have nothing better. This is a
        // guess, recorded as one, and it is revisited next time precisely
        // because guesses are not reused.
        if ($card->durationMs !== null && $card->durationMs <= $threshold) {
            return $this->default();
        }

        return new Dimensions(1280, 720);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function remember(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        try {
            VideoDimension::query()->upsert($rows, ['provider', 'provider_video_id'], ['width', 'height', 'source']);
        } catch (Throwable $e) {
            // Caching dimensions must never fail the feed that carries them.
            report($e);
        }
    }
}
