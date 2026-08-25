<?php

namespace App\Media\Storage;

use App\Api\Exceptions\ApiException;
use App\Media\YtDlp\YtDlpFailed;
use App\Media\YtDlp\YtDlpProcess;

/**
 * Transfers a media file with the yt-dlp binary.
 *
 * A plain HTTP GET on the format URL is a trap: YouTube throttles progressive
 * formats fetched in one request down to tens of KB/s, so a 12MB file can take
 * minutes. yt-dlp fetches in ranges and re-derives the signature when the
 * resolve's URL has gone stale — the part of it that changes most often.
 */
final class YtDlpMediaDownloader implements MediaDownloader
{
    public function download(string $sourceUrl, string $formatId, string $destination, int $maxBytes): DownloadedFile
    {
        $timeout = (int) config('media.storage.download_timeout', 300);

        $this->ensureDirectory($destination);

        try {
            $process = YtDlpProcess::run(
                $this->flags($formatId, $destination, $maxBytes),
                $sourceUrl,
                $timeout * 1000,
            );
        } catch (YtDlpFailed $e) {
            $this->discard($destination);

            throw $e;
        }

        $output = $process->getOutput()."\n".$process->getErrorOutput();

        // --max-filesize is a skip, not an error: yt-dlp exits 0 and writes
        // nothing, which would otherwise look like an unexplained missing file.
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
            // Exit zero with nothing on disk: the binary changed shape or
            // silently skipped.
            $this->discard($destination);

            throw new YtDlpFailed(
                stderr: trim($output),
                exitCode: 0,
                message: 'yt-dlp produced no file.',
            );
        }

        $bytes = (int) filesize($destination);

        if ($bytes > $maxBytes) {
            // --max-filesize relies on a declared size, which can be absent.
            // This checks what actually landed.
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
    private function flags(string $formatId, string $destination, int $maxBytes): array
    {
        return [
            // Exactly the format the resolve committed to, never a selector:
            // the response already told the client what it is getting.
            '-f', $formatId,

            '-o', $destination,

            // Straight to the destination: a .part file would leave the caller
            // looking for a name that is not there yet.
            '--no-part',
            '--no-continue',
            '--no-mtime',

            // Carriage-return progress updates would fill the process buffer.
            '--no-progress',

            '--max-filesize', (string) $maxBytes,

            // Generous: a queued transfer, not a call the client blocks on.
            '--socket-timeout', '15',
            '--retries', '3',
            '--fragment-retries', '3',
        ];
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
