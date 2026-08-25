<?php

namespace App\Media\Storage;

use InvalidArgumentException;

/**
 * Identifies a stored variant from the request alone.
 *
 * Derived from what the caller asked for rather than from what the extractor
 * chose, so a resolve can look it up *before* spawning yt-dlp and skip
 * extraction on a hit. It is also the value in the signed delivery URL, so it
 * has to be opaque: a plain video id would let anyone enumerate what this
 * deployment has cached.
 */
final readonly class AssetKey
{
    private function __construct(
        public string $value,
    ) {}

    public static function for(
        string $provider,
        string $providerVideoId,
        int $maxHeight,
        bool $audioOnly,
    ): self {
        return new self(hash('sha256', implode('|', [
            $provider,
            $providerVideoId,
            $maxHeight,
            $audioOnly ? '1' : '0',
        ])));
    }

    /**
     * Rebuild from a URL segment.
     *
     * Validated rather than trusted: this arrives from the network and is used
     * in database lookups.
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('Malformed asset key.');
        }

        return new self($value);
    }

    /**
     * Where the downloaded file lives on the media disk.
     *
     * Deliberately NOT the identity the key above carries: that one is derived
     * from the request, this one from the format actually selected, so two
     * variant rows that resolve to the same underlying file share one stored
     * object. Since itag 22 was removed, a 480p, 720p and 1080p request all
     * fall back to itag 18, and keying on the requested height would store the
     * same bytes three times.
     */
    public static function storagePath(
        string $provider,
        string $providerVideoId,
        string $formatId,
        string $container,
    ): string {
        return implode('/', [
            self::segment($provider),
            // Fan out across directories: some filesystems degrade badly with
            // tens of thousands of entries in one, and sha1 spreads evenly
            // where the raw id would not.
            substr(sha1($providerVideoId), 0, 2),
            self::segment($providerVideoId),
            self::segment($formatId).'.'.self::extension($container),
        ]);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Reduce an untrusted identifier to something safe as one path segment.
     *
     * Provider ids come from an extractor parsing a user-supplied URL, so a
     * `..` reaching a filesystem path is directory traversal. Anything unsafe
     * is hashed rather than stripped: stripping can collapse two distinct ids
     * onto one path, which would serve the wrong video.
     */
    private static function segment(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1) {
            return $value;
        }

        return substr(sha1($value), 0, 32);
    }

    private static function extension(string $container): string
    {
        $ext = strtolower($container);

        return preg_match('/^[a-z0-9]{1,8}$/', $ext) === 1 ? $ext : 'bin';
    }
}
