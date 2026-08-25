<?php

namespace App\Media\YtDlp;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the yt-dlp binary: shared flags, environment, and deadline in one place.
 *
 * Extraction and transfer are tuned very differently — seconds against the
 * client's budget versus minutes on a queue — but they run the same binary
 * against the same site, so the flags that make it work at all (cache dir,
 * pinned player clients, proxy) have to stay identical. Keeping them here is
 * what stops one caller being fixed and the other quietly left behind.
 */
final class YtDlpProcess
{
    /**
     * @param  array<int, string>  $flags  Caller-specific flags, appended to the shared ones.
     *
     * @throws YtDlpFailed when the deadline is exceeded
     */
    public static function run(array $flags, string $url, int $timeoutMs): Process
    {
        $process = new Process(self::command($flags, $url), env: self::environment());
        $process->setTimeout($timeoutMs / 1000);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw YtDlpFailed::timedOut($timeoutMs);
        }

        return $process;
    }

    /**
     * @param  array<int, string>  $flags
     * @return array<int, string>
     */
    private static function command(array $flags, string $url): array
    {
        $command = [
            (string) config('media.yt_dlp.binary', 'yt-dlp'),
            '--no-playlist',
            '--no-warnings',

            // Cached player JS: ~1-2s warm vs ~5-10s cold.
            '--cache-dir', (string) config('media.yt_dlp.cache_dir'),

            ...$flags,
        ];

        // See config/media.php for why these clients are pinned.
        if ($clients = config('media.yt_dlp.player_clients')) {
            $command[] = '--extractor-args';
            $command[] = 'youtube:player_client='.$clients;
        }

        if ($proxy = config('media.yt_dlp.proxy')) {
            $command[] = '--proxy';
            $command[] = (string) $proxy;
        }

        // Terminator: a URL beginning with a dash must never be read as a flag.
        $command[] = '--';
        $command[] = $url;

        return $command;
    }

    /**
     * The environment the binary needs, independent of the caller's.
     *
     * Solving YouTube's `n` challenge needs a JavaScript runtime on PATH;
     * without one yt-dlp still exits zero while dropping every format that
     * needs a PO token. The symptom is `media_missing` for videos that resolve
     * fine from a terminal, because a shell's PATH has the runtime and
     * PHP-FPM's does not.
     *
     * Symfony merges this over the inherited environment, so only PATH is
     * replaced.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $path = self::path();

        return $path === null ? [] : ['PATH' => $path];
    }

    private static function path(): ?string
    {
        $entries = [];

        // The binary's neighbours are usually the runtime and ffmpeg.
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
