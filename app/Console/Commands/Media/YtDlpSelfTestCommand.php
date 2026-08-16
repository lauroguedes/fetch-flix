<?php

namespace App\Console\Commands\Media;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\ProviderRegistry;
use App\Models\ExtractorHealth;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Proves the extractor still works against the live site.
 *
 * yt-dlp breaks when YouTube changes, often weekly. Without this, the first
 * report of a broken extractor comes from a user, by which time every resolve
 * has been failing for hours.
 */
class YtDlpSelfTestCommand extends Command
{
    protected $signature = 'yt-dlp:selftest {--quiet-success : Only report failures}';

    protected $description = 'Resolve a known-good video and record extractor health';

    public function handle(ProviderRegistry $registry): int
    {
        $url = (string) config('media.yt_dlp.selftest_url');
        $startedAt = hrtime(true);

        $ok = false;
        $failureCode = null;
        $notes = null;

        try {
            $provider = $registry->resolverFor($url);
            $resolved = $provider->resolve(new MediaRequest(url: $url, maxHeight: 360));

            // A resolve that returns no usable URL is a failure even though
            // nothing threw.
            $ok = $resolved->media->url !== '' && ! $resolved->media->isExpired();
            $notes = $ok ? null : 'Resolved but produced no usable stream URL.';
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
