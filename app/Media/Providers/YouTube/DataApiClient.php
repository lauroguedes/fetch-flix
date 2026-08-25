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
 * Two calls with wildly different costs: search.list at 100 units, videos.list
 * at 1 unit for up to 50 ids. So: search once for ids, then hydrate the whole
 * page's durations in a single 1-unit call. search.list returns no duration and
 * no dimensions at all, which is why the second call is not optional.
 */
final class DataApiClient
{
    private const BASE = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private readonly QuotaGuard $quota,
        private readonly YouTubeUrlParser $urls,
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
            // Coarse (under four minutes), the only filter search offers.
            // Exact filtering happens downstream.
            'videoDuration' => 'short',
            'maxResults' => (int) config('media.discovery.page_size', 50),
            'safeSearch' => 'moderate',
            'regionCode' => $query->regionCode,
            'pageToken' => $query->pageToken,
        ]));

        $items = is_array($response['items'] ?? null) ? $response['items'] : [];

        // Snippets are held rather than turned into cards straight away:
        // search.list carries no duration, so the ids have to make the
        // videos.list round trip before a card is complete.
        /** @var array<int, array{id: string, snippet: array<string, mixed>}> $found */
        $found = [];

        foreach ($items as $item) {
            $id = $item['id']['videoId'] ?? null;

            if (! is_string($id)) {
                continue;
            }

            $found[] = [
                'id' => $id,
                'snippet' => is_array($item['snippet'] ?? null) ? $item['snippet'] : [],
            ];
        }

        $ids = array_column($found, 'id');

        $durations = $ids === [] ? [] : $this->quota->spend(
            $tenant,
            $videosCost,
            fn () => $this->durations($ids)
        );

        $cards = [];

        foreach ($found as ['id' => $id, 'snippet' => $snippet]) {
            $cards[] = new VideoCard(
                id: $id,
                title: $this->decode($snippet['title'] ?? ''),
                channel: $this->decode($snippet['channelTitle'] ?? ''),
                durationMs: $durations[$id] ?? null,
                thumbnailUrl: $this->bestThumbnail($snippet),
                // Measured downstream by the DimensionResolver: neither Data
                // API call reports a size.
                width: 0,
                height: 0,
                provider: 'youtube',
                // Stated here, by the one class that knows both halves of the
                // answer, so no later layer has to reconstruct it.
                url: $this->urls->watchUrl($id),
            );
        }

        return new DiscoveryPage(
            videos: $cards,
            nextPageToken: is_string($response['nextPageToken'] ?? null) ? $response['nextPageToken'] : null,
            quotaUnitsSpent: $searchCost + ($ids === [] ? 0 : $videosCost),
        );
    }

    /**
     * One unit for up to 50 ids, so a full page costs the same as one lookup.
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
     * contentDetails.duration is ISO-8601, such as PT1M33S. Live and upcoming
     * broadcasts report P0D, a real zero rather than a parse failure, so it is
     * returned as 0 and filtered by callers.
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
            // Operator error, not client error: must not look permanent.
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
            // Google returns 403 for both quota exhaustion and a bad key, and
            // only the reason string tells them apart. Only the first retries.
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
        // Snippet text arrives HTML-escaped (&amp;, &#39;) and clients render
        // plain text, so it is decoded once here.
        return is_string($value)
            ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }
}
