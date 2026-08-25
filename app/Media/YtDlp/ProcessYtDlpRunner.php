<?php

namespace App\Media\YtDlp;

use JsonException;

/**
 * Runs the yt-dlp binary on this host.
 *
 * Every flag keeps one extraction inside the client's 12s synchronous budget.
 * The process is killed on its deadline rather than allowed to hang.
 */
final class ProcessYtDlpRunner implements YtDlpRunner
{
    public function extract(string $url): YtDlpResult
    {
        $timeoutMs = (int) config('media.resolve.process_timeout_ms', 8000);

        $startedAt = hrtime(true);

        $process = YtDlpProcess::run($this->flags(), $url, $timeoutMs);

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
            // Zero exit with unparseable stdout: the binary changed shape
            // under us. An extractor problem, not a client problem.
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
    private function flags(): array
    {
        return [
            '--dump-single-json',

            // No media transfer. This is a metadata call.
            '--skip-download',

            // A HEAD per format would blow the latency budget. This is the
            // default, stated so a future edit has to argue with it.
            '--no-check-formats',

            '--socket-timeout', '5',
            '--extractor-retries', '1',
        ];
    }
}
