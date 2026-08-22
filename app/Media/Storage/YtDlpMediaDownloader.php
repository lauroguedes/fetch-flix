<?php

namespace App\Media\Storage;

use App\Api\Exceptions\ApiException;
use App\Media\YtDlp\YtDlpFailed;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Transfers a media file with the yt-dlp binary.
 *
 * A plain HTTP GET on the format URL looks simpler and is a trap: YouTube
 * throttles progressive formats fetched in a single request down to tens of
 * KB/s, so a 12MB file can take minutes. yt-dlp already works around this by
 * fetching in ranges, and it re-derives the signature when the URL from the
 * original resolve has gone stale. Reimplementing either would be duplicating
 * the one part of yt-dlp that changes most often.
 */
final class YtDlpMediaDownloader implements MediaDownloader
{
    public function download(string $sourceUrl, string $formatId, string $destination, int $maxBytes): DownloadedFile
    {
        $timeout = (int) config('media.storage.download_timeout', 300);

        $this->ensureDirectory($destination);

        $process = new Process($this->command($sourceUrl, $formatId, $destination, $maxBytes));
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->discard($destination);

            throw YtDlpFailed::timedOut($timeout * 1000);
        }

        $output = $process->getOutput()."\n".$process->getErrorOutput();

        // --max-filesize is a skip, not an error: yt-dlp exits 0 and writes
        // nothing. Without this branch that would surface as an unexplained
        // missing file rather than as the ceiling doing its job.
        if (str_contains($output, 'larger than max-filesize')) {
            $this->discard($destination);

            throw ApiException::tooLarge(
                'This media is larger than the permitted maximum size.',
                context: ['max_bytes' => $maxBytes],
            );
        }

        if (! $process->isSuccessful()) {
            $this->discard($destination);

            throw new YtDlpFailed(
                stderr: trim($process->getErrorOutput()),
                exitCode: $process->getExitCode(),
                message: 'yt-dlp download failed.',
            );
        }

        clearstatcache(true, $destination);

        if (! is_file($destination) || filesize($destination) === 0) {
            // Exit zero with nothing on disk means the binary changed shape or
            // silently skipped. Either way there is nothing to store.
            $this->discard($destination);

            throw new YtDlpFailed(
                stderr: trim($output),
                exitCode: 0,
                message: 'yt-dlp produced no file.',
            );
        }

        $bytes = (int) filesize($destination);

        if ($bytes > $maxBytes) {
            // Belt and braces: --max-filesize relies on a declared size, which
            // can be absent. This checks what actually landed.
            $this->discard($destination);

            throw ApiException::tooLarge(
                'This media is larger than the permitted maximum size.',
                context: ['max_bytes' => $maxBytes, 'actual_bytes' => $bytes],
            );
        }

        return new DownloadedFile(
            path: $destination,
            bytes: $bytes,
            checksum: (string) hash_file('sha256', $destination),
        );
    }

    /**
     * @return array<int, string>
     */
    private function command(string $sourceUrl, string $formatId, string $destination, int $maxBytes): array
    {
        $command = [
            (string) config('media.yt_dlp.binary', 'yt-dlp'),

            // Exactly the format the resolve committed to. Never a selector
            // expression: the response already told the client the width,
            // height and size it is going to get.
            '-f', $formatId,

            '-o', $destination,

            // Write straight to the destination. A .part file would leave the
            // caller looking for a name that is not there yet.
            '--no-part',
            '--no-continue',
            '--no-mtime',
            '--no-playlist',
            '--no-warnings',

            // Progress bars are written as carriage-return updates and would
            // fill the process buffer with thousands of redundant lines.
            '--no-progress',

            '--max-filesize', (string) $maxBytes,

            // Generous compared to extraction: this is a transfer on a queue,
            // not a call the client is blocking on.
            '--socket-timeout', '15',
            '--retries', '3',
            '--fragment-retries', '3',

            '--cache-dir', (string) config('media.yt_dlp.cache_dir'),
        ];

        if ($clients = config('media.yt_dlp.player_clients')) {
            $command[] = '--extractor-args';
            $command[] = 'youtube:player_client='.$clients;
        }

        if ($proxy = config('media.yt_dlp.proxy')) {
            $command[] = '--proxy';
            $command[] = (string) $proxy;
        }

        $command[] = '--';
        $command[] = $sourceUrl;

        return $command;
    }

    private function ensureDirectory(string $destination): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function discard(string $destination): void
    {
        if (is_file($destination)) {
            unlink($destination);
        }
    }
}
