<?php

namespace App\Console\Commands\Media;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\ProviderRegistry;
use App\Media\Storage\MediaDownloader;
use App\Media\YtDlp\YtDlpErrorClassifier;
use App\Media\YtDlp\YtDlpFailed;
use App\Models\ExtractorHealth;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Proves the extractor still works against the live site, so a breakage is not
 * first reported by a user hours later.
 *
 * It transfers the file as well as resolving it: the two fail independently.
 * Measured 2026-08-22, yt-dlp's default player client extracted a well-formed
 * URL that answered 403 to every download, which a resolve-only check calls
 * healthy. The control video is 19s, so the whole path costs well under a
 * megabyte an hour.
 */
class YtDlpSelfTestCommand extends Command
{
    protected $signature = 'yt-dlp:selftest {--quiet-success : Only report failures}';

    protected $description = 'Resolve a known-good video and record extractor health';

    public function handle(
        ProviderRegistry $registry,
        MediaDownloader $downloader,
        YtDlpErrorClassifier $classifier,
    ): int {
        $url = (string) config('media.yt_dlp.selftest_url');
        $startedAt = hrtime(true);

        $ok = false;
        $failureCode = null;
        $notes = null;

        try {
            $provider = $registry->forUrl($url);
            $resolved = $provider->resolve(new MediaRequest(url: $url, maxHeight: 360));

            // A resolve that returns no usable URL is a failure even though
            // nothing threw.
            $healthy = $resolved->media->url !== '' && ! $resolved->media->isExpired();

            if (! $healthy) {
                $notes = 'Resolved but produced no usable stream URL.';
            } elseif ($resolved->media->formatId !== null) {
                $healthy = $this->transfer($downloader, $classifier, $url, $resolved->media->formatId) > 0;

                if (! $healthy) {
                    $notes = 'Resolved successfully but the transfer produced no bytes.';
                }
            }

            // Assigned last so anything thrown above leaves the check failed.
            // Setting it before the transfer made a 403ing download look
            // healthy, the exact failure this command exists to catch.
            $ok = $healthy;
        } catch (ApiException $e) {
            $failureCode = $e->errorCode->value;
            $notes = $e->getMessage();
        } catch (Throwable $e) {
            $failureCode = 'unexpected';
            $notes = $e->getMessage();
        }

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        ExtractorHealth::query()->create([
            'provider' => 'youtube',
            'yt_dlp_version' => $this->binaryVersion(),
            'ok' => $ok,
            'failure_code' => $failureCode,
            'notes' => $notes,
            'duration_ms' => $durationMs,
            'checked_at' => now(),
        ]);

        if ($ok) {
            if (! $this->option('quiet-success')) {
                $this->components->info("Extractor healthy ({$durationMs}ms).");
            }

            return self::SUCCESS;
        }

        $this->components->error("Extractor unhealthy: {$failureCode} - {$notes}");

        return self::FAILURE;
    }

    /**
     * Download the control video and throw the bytes away.
     *
     * @throws ApiException on a transfer failure, so it lands on the health row
     *                      with the same code a client would have seen
     */
    private function transfer(
        MediaDownloader $downloader,
        YtDlpErrorClassifier $classifier,
        string $url,
        string $formatId,
    ): int {
        $destination = rtrim((string) config('media.storage.tmp_dir', storage_path('app/media-tmp')), '/')
            .'/selftest-'.sha1($url).'.tmp';

        try {
            return $downloader->download($url, $formatId, $destination, 64 * 1024 * 1024)->bytes;
        } catch (YtDlpFailed $e) {
            // Classified rather than raw, so the health row carries the same
            // code a client would have seen instead of "unexpected".
            throw $classifier->classify($e);
        } finally {
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }

    /**
     * Pinned to the health row so a regression maps to a specific binary.
     */
    private function binaryVersion(): ?string
    {
        try {
            $process = new Process([(string) config('media.yt_dlp.binary', 'yt-dlp'), '--version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
