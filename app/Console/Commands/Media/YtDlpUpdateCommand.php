<?php

namespace App\Console\Commands\Media;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Updates yt-dlp behind a self-test, and rolls back if the update breaks it.
 *
 * YouTube changes weekly and yt-dlp follows, so staying current is routine
 * maintenance rather than an upgrade project. The risk is the reverse of the
 * usual one: a new build is more likely to fix extraction than break it, but
 * when it does break it, every resolve fails at once. Verifying before keeping
 * the new binary is what makes running nightly safe.
 */
class YtDlpUpdateCommand extends Command
{
    protected $signature = 'yt-dlp:update
        {--channel= : Release channel to update to (defaults to config)}
        {--no-rollback : Keep the new build even if the self-test fails}';

    protected $description = 'Update yt-dlp, verifying with a self-test and rolling back on failure';

    public function handle(): int
    {
        $before = $this->version();

        if ($before === null) {
            $this->components->error('Could not determine the current yt-dlp version.');

            return self::FAILURE;
        }

        $channel = (string) ($this->option('channel') ?: config('media.yt_dlp.channel', 'nightly'));

        $this->components->twoColumnDetail('Current', $before);
        $this->components->twoColumnDetail('Channel', $channel);

        $update = $this->runBinary(['--update-to', $channel]);

        if (! $update['ok']) {
            // Package-managed installs (homebrew, apt, pip) refuse to self
            // update. That is a deployment choice, not a failure to hide: say so
            // plainly rather than reporting a successful no-op.
            $this->components->error('yt-dlp could not update itself.');
            $this->line($update['output']);
            $this->components->warn(
                'If yt-dlp is package-managed, update it through that package manager instead '.
                'and run yt-dlp:selftest afterwards.'
            );

            return self::FAILURE;
        }

        $after = $this->version();
        $this->components->twoColumnDetail('Updated to', $after ?? 'unknown');

        if ($after === $before) {
            $this->components->info('Already current; nothing to verify.');

            return self::SUCCESS;
        }

        // The whole point: prove the new build works before trusting it.
        if ($this->call('yt-dlp:selftest') === self::SUCCESS) {
            $this->components->info("Update verified ({$before} -> {$after}).");

            return self::SUCCESS;
        }

        if ($this->option('no-rollback')) {
            $this->components->error('Self-test failed. Keeping the new build as instructed.');

            return self::FAILURE;
        }

        $this->components->warn("Self-test failed on {$after}; rolling back to {$before}.");

        $rollback = $this->runBinary(['--update-to', "{$channel}@{$before}"]);

        if (! $rollback['ok']) {
            // Worth shouting about: the extractor is now on a build known to
            // fail and we could not get off it.
            $this->components->error("Rollback to {$before} failed. Extractor is on a broken build.");
            $this->line($rollback['output']);

            return self::FAILURE;
        }

        $this->call('yt-dlp:selftest');
        $this->components->info("Rolled back to {$before}.");

        return self::FAILURE;
    }

    /**
     * @param  array<int, string>  $args
     * @return array{ok: bool, output: string}
     */
    private function runBinary(array $args): array
    {
        try {
            $process = new Process([(string) config('media.yt_dlp.binary', 'yt-dlp'), ...$args]);
            $process->setTimeout(180);
            $process->run();

            return [
                'ok' => $process->isSuccessful(),
                'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        }
    }

    private function version(): ?string
    {
        $result = $this->runBinary(['--version']);

        return $result['ok'] ? trim($result['output']) : null;
    }
}
