<?php

namespace App\Media\YtDlp;

use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the yt-dlp binary on this host.
 *
 * Every flag here is chosen to keep a single extraction inside the client's
 * synchronous budget. The client calls resolve from a blocking runloop with a
 * 12s total timeout, so this process gets a hard deadline well under that and
 * is killed rather than allowed to hang.
 */
final class ProcessYtDlpRunner implements YtDlpRunner
{
    public function extract(string $url): YtDlpResult
    {
        $timeoutMs = (int) config('media.resolve.process_timeout_ms', 8000);

        $process = new Process($this->command($url));
        $process->setTimeout($timeoutMs / 1000);

        $startedAt = hrtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw YtDlpFailed::timedOut($timeoutMs);
        }

        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if (! $process->isSuccessful()) {
            throw new YtDlpFailed(
                stderr: trim($process->getErrorOutput()),
                exitCode: $process->getExitCode(),
            );
        }

        try {
            $info = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // A zero exit with unparseable stdout means the binary changed shape
            // under us. That is an extractor problem, not a client problem.
            throw new YtDlpFailed(
                stderr: 'Unparseable JSON from yt-dlp: '.$e->getMessage(),
                exitCode: 0,
            );
        }

        if (! is_array($info)) {
            throw new YtDlpFailed(stderr: 'yt-dlp returned a non-object info dict.', exitCode: 0);
        }

        return new YtDlpResult($info, $elapsedMs);
    }

    /**
     * @return array<int, string>
     */
    private function command(string $url): array
    {
        $command = [
            (string) config('media.yt_dlp.binary', 'yt-dlp'),
            '--dump-single-json',
            // No media transfer. This is a metadata call.
            '--skip-download',
            '--no-playlist',
            '--no-warnings',
            // Validating format URLs would add a HEAD request per format and
            // blow the latency budget. This is the default; it is stated
            // explicitly so a future edit has to argue with it.
            '--no-check-formats',
            '--socket-timeout', '5',
            '--extractor-retries', '1',
            // Keeping the player JS cached across invocations is the difference
            // between a ~1-2s warm extraction and a ~5-10s cold one.
            '--cache-dir', (string) config('media.yt_dlp.cache_dir'),
        ];

        // tv and web_embedded require no PO token, which is the cheapest
        // mitigation when datacenter bot checks start appearing.
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
}
