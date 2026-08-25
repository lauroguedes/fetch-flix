<?php

namespace App\Media\Storage;

use App\Api\ErrorCode;
use App\Media\Data\AssetStatus;
use App\Models\MediaAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every state transition an asset can make, in one place.
 *
 * Written as conditional updates rather than read-then-write: several workers
 * and web requests race on the same row by design, and a lost race has to be a
 * no-op rather than a second download of the same file.
 */
final class MediaAssetRepository
{
    public function findByKey(AssetKey $key): ?MediaAsset
    {
        return MediaAsset::query()->where('asset_key', (string) $key)->first();
    }

    /**
     * Get the row for this variant, creating it as pending if absent.
     *
     * Metadata is refreshed on every resolve *except* once stored: a stored row
     * describes a file that exists, and its size and dimensions must keep
     * describing that file rather than the latest extractor report.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(AssetKey $key, array $attributes): MediaAsset
    {
        $asset = MediaAsset::query()->firstOrCreate(
            ['asset_key' => (string) $key],
            $attributes + ['status' => AssetStatus::Pending],
        );

        if (! $asset->wasRecentlyCreated && $asset->status !== AssetStatus::Stored) {
            $asset->fill($attributes)->save();
        }

        return $asset;
    }

    /**
     * Atomically take ownership of a download.
     *
     * False means someone else holds it and the caller should do nothing. A row
     * left in `downloading` by a dead worker is reclaimable after `staleAfter`.
     */
    public function claim(MediaAsset $asset, int $maxAttempts, int $staleAfter): bool
    {
        $stale = Carbon::now()->subSeconds($staleAfter);

        $affected = MediaAsset::query()
            ->whereKey($asset->getKey())
            ->where('attempts', '<', $maxAttempts)
            ->where(function ($query) use ($stale) {
                $query
                    ->whereIn('status', [AssetStatus::Pending, AssetStatus::Failed])
                    ->orWhere(function ($query) use ($stale) {
                        $query
                            ->where('status', AssetStatus::Downloading)
                            ->where('updated_at', '<', $stale);
                    });
            })
            ->update([
                'status' => AssetStatus::Downloading,
                'attempts' => DB::raw('attempts + 1'),
                'error_code' => null,
            ]);

        if ($affected !== 1) {
            return false;
        }

        $asset->refresh();

        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markStored(MediaAsset $asset, array $attributes): void
    {
        $asset->fill($attributes + [
            'status' => AssetStatus::Stored,
            'error_code' => null,
            'downloaded_at' => Carbon::now(),
            'last_accessed_at' => Carbon::now(),
        ])->save();
    }

    public function markFailed(MediaAsset $asset, ErrorCode $code): void
    {
        $asset->fill([
            'status' => AssetStatus::Failed,
            'error_code' => $code->value,
        ])->save();
    }

    /**
     * Record that somebody wanted this file: a popularity signal for retention,
     * not a meter (a local-disk response answers each byte-range separately).
     *
     * Written without touching `updated_at`, the heartbeat `claim()` uses to
     * detect a dead worker: bumping it would make a stranded download look alive
     * and block its reclaim.
     */
    public function touchAccess(MediaAsset $asset, int $throttle = 3600): void
    {
        $query = MediaAsset::query()->toBase()->where('id', $asset->getKey());

        if ($asset->last_accessed_at?->gt(Carbon::now()->subSeconds($throttle))) {
            $query->increment('serve_count');

            return;
        }

        $query->increment('serve_count', 1, ['last_accessed_at' => Carbon::now()]);
    }

    /**
     * Whether any other row still points at this file.
     *
     * `path` is shared: a 720p and a 1080p variant both land on itag 18, so
     * deleting the file with the first row would break the second.
     */
    public function pathIsShared(MediaAsset $asset): bool
    {
        if ($asset->path === null) {
            return false;
        }

        return MediaAsset::query()
            ->where('path', $asset->path)
            ->where('disk', $asset->disk)
            ->whereKeyNot($asset->getKey())
            ->exists();
    }
}
