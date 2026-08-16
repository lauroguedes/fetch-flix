<?php

namespace App\Media\Providers\YouTube;

use App\Api\Exceptions\ApiException;
use App\Media\Data\DiscoveryPage;
use App\Media\Data\DiscoveryQuery;
use App\Media\Data\VideoCard;
use App\Media\Discovery\QuotaGuard;
use App\Models\Tenant;
use DateInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The YouTube Data API v3.
 *
 * Two calls, with wildly different costs:
 *   search.list  100 units, and the only expensive call in the system
 *   videos.list    1 unit for up to 50 ids
 *
 * So the shape here is deliberate: search once to get ids, then hydrate the
 * whole page's durations in a single 1-unit call. search.list returns no
 * duration and no dimensions at all, which is why the second call is not
 * optional.
 */
final class DataApiClient
{
    private const BASE = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private readonly QuotaGuard $quota,
    ) {}

    public function search(Tenant $tenant, DiscoveryQuery $query): DiscoveryPage
    {
        $costs = (array) config('media.quota.costs', []);
        $searchCost = (int) ($costs['search.list'] ?? 100);
        $videosCost = (int) ($costs['videos.list'] ?? 1);

        $response = $this->quota->spend($tenant, $searchCost, fn () => $this->get('search', [
            'part' => 'snippet',
            'type' => 'video',
            'q' => $query->normalisedTerm(),
            // Coarse (under four minutes) but it is the only duration filter
            // the search endpoint offers. Exact filtering happens downstream.
            'videoDuration' => 'short',
            'maxResults' => (int) config('media.discovery.page_size', 50),
            'safeSearch' => 'moderate',
            'regionCode' => $query->regionCode,
            'pageToken' => $query->pageToken,
        ]));

        $items = is_array($response['items'] ?? null) ? $response['items'] : [];

        $cards = [];
        $ids = [];

        foreach ($items as $item) {
            $id = $item['id']['videoId'] ?? null;

            if (! is_string($id)) {
                continue;
            }

            $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
            $ids[] = $id;

            $cards[$id] = new VideoCard(
                id: $id,
                title: $this->decode($snippet['title'] ?? ''),
                channel: $this->decode($snippet['channelTitle'] ?? ''),
                // Filled by the videos.list call below.
                durationMs: null,
                thumbnailUrl: $this->bestThumbnail($snippet),
                width: 0,
                height: 0,
            );
        }

        $durations = $ids === [] ? [] : $this->quota->spend(
            $tenant,
            $videosCost,
            fn () => $this->durations($ids)
        );

        $hydrated = [];

        foreach ($cards as $id => $card) {
            $hydrated[] = new VideoCard(
                id: $card->id,
                title: $card->title,
                channel: $card->channel,
                durationMs: $durations[$id] ?? null,
                thumbnailUrl: $card->thumbnailUrl,
                width: $card->width,
                height: $card->height,
            );
        }

        return new DiscoveryPage(
            videos: $hydrated,
            nextPageToken: is_string($response['nextPageToken'] ?? null) ? $response['nextPageToken'] : null,
            quotaUnitsSpent: $searchCost + ($ids === [] ? 0 : $videosCost),
        );
    }

    /**
     * One unit for up to 50 ids, so a full page's durations cost the same as a
     * single lookup.
     *
     * @param  array<int, string>  $ids
     * @return array<string, int>
     */
    private function durations(array $ids): array
    {
        $response = $this->get('videos', [
            'part' => 'contentDetails',
            'id' => implode(',', array_slice($ids, 0, 50)),
        ]);

        $durations = [];

        foreach ((is_array($response['items'] ?? null) ? $response['items'] : []) as $item) {
            $id = $item['id'] ?? null;
            $iso = $item['contentDetails']['duration'] ?? null;

            if (is_string($id) && is_string($iso)) {
                $ms = self::isoDurationToMs($iso);

                if ($ms !== null) {
                    $durations[$id] = $ms;
                }
            }
        }

        return $durations;
    }

    /**
     * contentDetails.duration is an ISO-8601 duration such as PT1M33S.
     *
     * Live and upcoming broadcasts report P0D, which is a real zero rather than
     * a parse failure, so it is returned as 0 and filtered by callers.
     */
    public static function isoDurationToMs(string $iso): ?int
    {
        try {
            $interval = new DateInterval($iso);
        } catch (Throwable) {
            return null;
        }

        $seconds = ((($interval->y * 365 + $interval->m * 30 + $interval->d) * 24
            + $interval->h) * 60
            + $interval->i) * 60
            + $interval->s;

        return $seconds * 1000;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function get(string $endpoint, array $params): array
    {
        $key = config('media.discovery.youtube_api_key');

        if (! is_string($key) || $key === '') {
            // A missing key is an operator error, not a client error, so it must
            // not look like a permanent problem with the caller's request.
            throw ApiException::resolverUnavailable(
                retryAfter: 30,
                message: 'Discovery is not configured.',
                context: ['reason' => 'missing_api_key'],
            );
        }

        $params = array_filter($params, fn ($value) => $value !== null && $value !== '');
        $params['key'] = $key;

        try {
            $response = Http::timeout(6)->connectTimeout(3)->get(self::BASE."/{$endpoint}", $params);
        } catch (ConnectionException $e) {
            throw ApiException::offline(message: 'The discovery provider could not be reached.', context: [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
        }

        if ($response->status() === 403) {
            // Google returns 403 for both quota exhaustion and a bad key. Only
            // the former is worth retrying, and the reason string is the only
            // thing that distinguishes them.
            $reason = (string) ($response->json('error.errors.0.reason') ?? '');

            if (str_contains($reason, 'quota')) {
                throw ApiException::rateLimited(
                    retryAfter: $this->quota->secondsUntilReset(),
                    scope: QuotaGuard::SCOPE_GLOBAL,
                    message: 'The daily discovery quota for this service is exhausted.',
                );
            }

            throw ApiException::resolverUnavailable(
                retryAfter: 60,
                message: 'Discovery is not configured correctly.',
                context: ['reason' => $reason],
            );
        }

        if ($response->failed()) {
            throw ApiException::downloadFailed(
                message: 'The discovery provider returned an error.',
                context: ['status' => $response->status(), 'endpoint' => $endpoint],
            );
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    /**
     * @param  array<string, mixed>  $snippet
     */
    private function bestThumbnail(array $snippet): ?string
    {
        $thumbnails = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];

        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
            $url = $thumbnails[$size]['url'] ?? null;

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function decode(mixed $value): string
    {
        // Snippet text arrives HTML-escaped (&amp;, &#39;). Clients render it as
        // plain text, so it is decoded once here rather than in every consumer.
        return is_string($value)
            ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }
}
