<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Canned Data API responses.
 *
 * Shapes match the documented search.list and videos.list payloads: search
 * returns snippet data and no duration or dimensions at all, which is precisely
 * why a second videos.list call exists.
 */
final class YouTubeApiFake
{
    /**
     * @param  array<int, array{id: string, title?: string, channel?: string, duration?: string}>  $videos
     */
    public static function fake(array $videos, ?string $nextPageToken = 'CAUQAA'): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/search*' => Http::response([
                'nextPageToken' => $nextPageToken,
                'items' => array_map(fn (array $v) => [
                    'id' => ['kind' => 'youtube#video', 'videoId' => $v['id']],
                    'snippet' => [
                        // Deliberately HTML-escaped, as the real API returns it.
                        'title' => $v['title'] ?? 'Ordinary &amp; fine title',
                        'channelTitle' => $v['channel'] ?? 'Some Channel',
                        'thumbnails' => [
                            'default' => ['url' => "https://i.ytimg.com/vi/{$v['id']}/default.jpg"],
                            'high' => ['url' => "https://i.ytimg.com/vi/{$v['id']}/hqdefault.jpg"],
                        ],
                    ],
                ], $videos),
            ]),

            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => array_map(fn (array $v) => [
                    'id' => $v['id'],
                    'contentDetails' => ['duration' => $v['duration'] ?? 'PT45S'],
                ], $videos),
            ]),
        ]);
    }

    public static function fakeQuotaExhausted(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/*' => Http::response([
                'error' => [
                    'code' => 403,
                    'errors' => [[
                        'reason' => 'quotaExceeded',
                        'message' => 'The request cannot be completed because you have exceeded your quota.',
                    ]],
                ],
            ], 403),
        ]);
    }

    public static function fakeBadKey(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/*' => Http::response([
                'error' => [
                    'code' => 403,
                    'errors' => [['reason' => 'keyInvalid', 'message' => 'Bad Request']],
                ],
            ], 403),
        ]);
    }
}
