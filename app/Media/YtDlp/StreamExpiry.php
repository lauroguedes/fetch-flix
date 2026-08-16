<?php

namespace App\Media\YtDlp;

use Illuminate\Support\Carbon;

/**
 * Determines when a stream URL stops working.
 *
 * The client re-resolves rather than reusing a stale URL, so this value has to
 * be right. Guessing a fixed TTL would either expire usable URLs early or hand
 * out dead ones, and the latter surfaces to the user as a failed download with
 * no useful error.
 */
final class StreamExpiry
{
    /**
     * googlevideo URLs carry the expiry as an `expire` query parameter holding a
     * Unix timestamp, or as an `/expire/<ts>/` path segment on some hosts. Both
     * are integrity-protected by the URL's signature, so they can be trusted as
     * read and cannot be extended by editing them.
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
     * Applies the safety margin.
     *
     * A client that resolves at T and starts its download at T+90 must not be
     * handed a URL that dies mid-transfer, so the advertised expiry is pulled
     * back from the real one.
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
