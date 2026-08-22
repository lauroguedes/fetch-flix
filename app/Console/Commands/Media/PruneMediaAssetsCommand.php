<?php

namespace App\Console\Commands\Media;

use App\Media\Data\AssetStatus;
use App\Media\Storage\MediaAssetRepository;
use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Collects stored media nobody is asking for any more.
 *
 * Not optional. Handing back a provider URL cost nothing to keep; holding the
 * file costs money every month it stays, and an uncollected library grows
 * without bound. Short retention is also part of the posture that came with
 * deciding to host the bytes at all.
 */
class PruneMediaAssetsCommand extends Command
{
    protected $signature = 'media:prune
        {--days= : Override the configured retention window}
        {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Delete stored media that has not been requested inside the retention window';

    public function handle(MediaAssetRepository $assets): int
    {
        $days = (int) ($this->option('days') ?? config('media.storage.retention_days', 30));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $expired = MediaAsset::query()
            ->where('status', AssetStatus::Stored)
            // A stored row with no access stamp has never been served, so its
            // creation time is the only signal there is.
            ->where(fn ($query) => $query
                ->where('last_accessed_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('last_accessed_at')->where('created_at', '<', $cutoff))
            )
            ->get();

        $freed = 0;

        foreach ($expired as $asset) {
            $freed += $asset->bytes ?? 0;

            if (! $dryRun) {
                $this->forget($assets, $asset);
            }
        }

        // Failed rows are not holding a file, but they are holding the variant:
        // while one exists, a resolve will keep answering with a URL that leads
        // to an error. Clearing them lets the next resolve start over.
        $stale = MediaAsset::query()
            ->where('status', AssetStatus::Failed)
            ->where('updated_at', '<', now()->subDay());

        $staleCount = $dryRun ? $stale->count() : $stale->delete();

        $this->components->info(sprintf(
            '%s %d stored asset(s) (%s) and %d failed row(s).',
            $dryRun ? 'Would remove' : 'Removed',
            $expired->count(),
            $this->humanise($freed),
            $staleCount,
        ));

        return self::SUCCESS;
    }

    /**
     * Delete the row, and the file only if nothing else points at it.
     *
     * Two variants of the same video routinely share one object, so deleting
     * the file with the first row would break the second.
     */
    private function forget(MediaAssetRepository $assets, MediaAsset $asset): void
    {
        $shared = $assets->pathIsShared($asset);

        if (! $shared && $asset->disk !== null && $asset->path !== null) {
            try {
                Storage::disk($asset->disk)->delete($asset->path);
            } catch (Throwable $e) {
                // A file we cannot delete is a cost, not a correctness problem.
                // Losing the row as well would make it an orphan nobody can
                // find again, so the row stays until the delete succeeds.
                report($e);

                return;
            }
        }

        $asset->delete();
    }

    private function humanise(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1).' MB';
        }

        return round($bytes / 1024 / 1024 / 1024, 2).' GB';
    }
}
