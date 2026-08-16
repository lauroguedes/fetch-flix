<?php

namespace App\Media\Providers\YouTube;

use App\Api\Exceptions\ApiException;

/**
 * Recognises the URL shapes YouTube actually hands out.
 *
 * Clients paste whatever the share sheet gave them, so all of these arrive in
 * practice: watch links, youtu.be short links, Shorts, embeds, and live URLs.
 */
final class YouTubeUrlParser
{
    private const HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'youtu.be',
        'www.youtu.be',
    ];

    /** YouTube ids are exactly 11 characters of URL-safe base64. */
    private const ID = '/^[A-Za-z0-9_-]{11}$/';

    public function claims(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::HOSTS, true);
    }

    public function parse(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        parse_str($query, $params);

        // youtu.be/<id>
        if (str_ends_with($host, 'youtu.be')) {
            return $this->validate(trim($path, '/'));
        }

        // /watch?v=<id>
        if (is_string($params['v'] ?? null)) {
            return $this->validate($params['v']);
        }

        // /shorts/<id>, /embed/<id>, /live/<id>, /v/<id>
        // Tilde-delimited: a '#' delimiter would be terminated by the '#' inside
        // the character class.
        if (preg_match('~^/(?:shorts|embed|live|v)/([^/?#]+)~', $path, $matches) === 1) {
            return $this->validate($matches[1]);
        }

        throw ApiException::unresolvable('No YouTube video id could be read from that URL.');
    }

    /**
     * Whether the URL was shared in Shorts form.
     *
     * Worth preserving: the oEmbed endpoint reports 16:9 for a vertical video
     * when queried with the /watch form and the true portrait dimensions when
     * queried with the /shorts form, so this determines which one to ask.
     */
    public function isShortsUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_starts_with($path, '/shorts/');
    }

    public function watchUrl(string $id): string
    {
        return "https://www.youtube.com/watch?v={$id}";
    }

    private function validate(string $candidate): string
    {
        if (preg_match(self::ID, $candidate) !== 1) {
            throw ApiException::unresolvable('That YouTube video id is malformed.');
        }

        return $candidate;
    }
}
