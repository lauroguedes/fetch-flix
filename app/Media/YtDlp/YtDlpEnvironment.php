<?php

namespace App\Media\YtDlp;

/**
 * The environment the extractor binary needs, independent of the caller's.
 *
 * yt-dlp does not run alone. Solving YouTube's `n` challenge requires a
 * JavaScript runtime on PATH, and without one the extractor still exits zero
 * while quietly dropping every format that needs a PO token. The visible
 * symptom is `media_missing` for videos that resolve fine from a terminal,
 * because a shell's PATH has the runtime and PHP-FPM's does not.
 *
 * Inheriting the web server's PATH is therefore not good enough: the same code
 * has to behave identically from a queue worker, an artisan command and a
 * request served by Herd, nginx or Octane.
 */
final class YtDlpEnvironment
{
    /**
     * Environment overrides for a yt-dlp process.
     *
     * Symfony merges these over the inherited environment, so only PATH is
     * replaced and everything else the process needs is left alone.
     *
     * @return array<string, string>
     */
    public static function forProcess(): array
    {
        $path = self::path();

        return $path === null ? [] : ['PATH' => $path];
    }

    private static function path(): ?string
    {
        $entries = [];

        // Where the binary itself lives. Its neighbours are usually the runtime
        // and ffmpeg, installed by the same package manager.
        $binary = (string) config('media.yt_dlp.binary', 'yt-dlp');

        if (str_contains($binary, '/')) {
            $entries[] = dirname($binary);
        }

        foreach (explode(':', (string) config('media.yt_dlp.path', '')) as $entry) {
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        $inherited = getenv('PATH');

        if (is_string($inherited) && $inherited !== '') {
            $entries[] = $inherited;
        }

        $entries = array_values(array_unique(array_filter($entries)));

        return $entries === [] ? null : implode(':', $entries);
    }
}
