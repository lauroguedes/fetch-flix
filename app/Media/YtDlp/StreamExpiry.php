<?php

namespace App\Media\YtDlp;

use Illuminate\Support\Carbon;

/**
 * Determines when a stream URL stops working.
 *
 * The client re-resolves rather than reusing a stale URL, so this has to be
 * right: a guessed TTL either expires usable URLs early or hands out dead ones.
 */
final class StreamExpiry
{
    /**
     * googlevideo carries the expiry as an `expire` query parameter or an
     * `/expire/<ts>/` path segment. Both are covered by the URL's signature, so
     * they can be trusted as read and cannot be extended by editing.
     */
    public static function from(string $url, ?int $fallbackSeconds = null): Carbon
    {
        $timestamp = self::parse($url);

        if ($timestamp !== null) {
            return Carbon::createFromTimestampUTC($timestamp);
        }

        $fallback = $fallbackSeconds ?? (int) config('media.resolve.default_expiry', 3600);

        return Carbon::now()->addSeconds($fallback);
    }

    /**
     * Pull the advertised expiry back from the real one, so a client that
     * resolves at T and downloads at T+90 is not handed a dying URL.
     */
    public static function withSafetyMargin(Carbon $expiresAt): Carbon
    {
        $margin = (int) config('media.resolve.expiry_safety_margin', 120);

        return $expiresAt->copy()->subSeconds($margin);
    }

    public static function parse(string $url): ?int
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $params);

            if (isset($params['expire']) && is_numeric($params['expire'])) {
                return (int) $params['expire'];
            }
        }

        if (preg_match('#/expire/(\d+)#', $url, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
