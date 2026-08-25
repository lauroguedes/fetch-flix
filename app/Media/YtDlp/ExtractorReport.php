<?php

namespace App\Media\YtDlp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Rolling hourly counters for extractor trouble, surfaced by /health.
 *
 * Bot checks are counted apart from breakages because the remedy differs: a bot
 * check is about where our traffic originates, a breakage about the binary
 * being out of date against a site that changed. A single odd failure is noise;
 * a sustained run in either counter is an incident.
 */
final class ExtractorReport
{
    private const BOT_CHECKS = 'extractor:botcheck:count';

    private const BREAKAGES = 'extractor:broken:count';

    public static function botCheck(string $stderr): void
    {
        Log::warning('YouTube served a bot check', [
            'count_last_hour' => self::increment(self::BOT_CHECKS),
            'stderr' => self::redact(mb_substr($stderr, 0, 500)),
        ]);
    }

    /**
     * @param  bool  $matched  False when nothing in the pattern table matched,
     *                         which is the strongest signal that YouTube
     *                         changed under us.
     */
    public static function broken(string $stderr, bool $matched): void
    {
        Log::error('yt-dlp extraction failed', [
            'matched_known_pattern' => $matched,
            // Writing a new stderr pattern requires seeing the actual text.
            'stderr' => self::redact(mb_substr($stderr, 0, 2000)),
            'count_last_hour' => self::increment(self::BREAKAGES),
            'yt_dlp_version' => config('media.yt_dlp.channel'),
        ]);
    }

    /**
     * Strip URLs down to their host before anything is written to a log.
     *
     * yt-dlp echoes the URLs it worked on, and a googlevideo format URL carries
     * its own signature in the query string. Logs are long-lived, are shipped
     * off the box, and are read by more people than the media is meant for, so
     * the credential must not travel with the diagnostic. The host survives
     * because "which CDN answered" is most of the debugging value.
     */
    public static function redact(string $stderr): string
    {
        return (string) preg_replace_callback(
            '~https?://[^\s"\'<>]+~i',
            function (array $matches): string {
                $host = parse_url($matches[0], PHP_URL_HOST);

                return is_string($host) ? "[url {$host}]" : '[url]';
            },
            $stderr
        );
    }

    public static function botChecksThisHour(): int
    {
        return self::count(self::BOT_CHECKS);
    }

    public static function breakagesThisHour(): int
    {
        return self::count(self::BREAKAGES);
    }

    private static function increment(string $counter): int
    {
        $key = self::key($counter);

        // add() is atomic and is what attaches the expiry; increment() alone
        // would create a key with no TTL.
        Cache::add($key, 0, 3600);

        return (int) Cache::increment($key);
    }

    private static function count(string $counter): int
    {
        return (int) (Cache::get(self::key($counter)) ?? 0);
    }

    private static function key(string $counter): string
    {
        return $counter.':'.now()->format('Y-m-d-H');
    }
}
