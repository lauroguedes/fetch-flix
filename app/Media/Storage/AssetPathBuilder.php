<?php

namespace App\Media\Storage;

/**
 * Where a downloaded file lives on the media disk.
 *
 * Derived from the *selected format*, not from the request, so two variant rows
 * that resolve to the same underlying file share one stored object. On YouTube
 * that is the normal case rather than an edge one: since itag 22 was removed, a
 * 480p, 720p and 1080p request all fall back to itag 18, and keying the path on
 * the requested height would store the same bytes three times.
 *
 * Every segment is sanitised. Provider ids come from an extractor parsing a
 * user-supplied URL, and a `..` reaching a filesystem path is a directory
 * traversal, not a naming quirk.
 */
final class AssetPathBuilder
{
    public function build(
        string $provider,
        string $providerVideoId,
        string $formatId,
        string $container,
    ): string {
        $id = $this->segment($providerVideoId);

        return implode('/', [
            $this->segment($provider),
            // Fan out across directories. Some filesystems degrade badly with
            // tens of thousands of entries in one directory, and sha1 spreads
            // evenly where the raw id would not.
            substr(sha1($providerVideoId), 0, 2),
            $id,
            $this->segment($formatId).'.'.$this->extension($container),
        ]);
    }

    /**
     * Reduce an untrusted identifier to something safe as a single path segment.
     *
     * Anything that is not plainly safe is replaced by a hash rather than
     * stripped: stripping can collapse two distinct ids onto one path, which
     * would serve the wrong video.
     */
    private function segment(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1) {
            return $value;
        }

        return substr(sha1($value), 0, 32);
    }

    private function extension(string $container): string
    {
        $ext = strtolower($container);

        return preg_match('/^[a-z0-9]{1,8}$/', $ext) === 1 ? $ext : 'bin';
    }
}
