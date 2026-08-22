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
 * The transitions are written as conditional updates rather than
 * read-then-write, because several workers and several web requests race on the
 * same row by design. A lost race has to be a no-op, not a second download of
 * the same file.
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
     * Metadata is refreshed on every resolve *except* once the asset is stored.
     * A stored row describes a file that actually exists, and its recorded size
     * and dimensions have to keep describing that file rather than whatever the
     * extractor reported most recently.
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
     * Returns false when someone else already holds it, which is the signal for
     * the caller to do nothing at all. A row left in `downloading` by a worker
     * that died is reclaimable after `staleAfter`, otherwise a single crash
     * would strand that video forever.
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
     * Record that somebody actually wanted this file.
     *
     * The delivery count is always incremented; the access stamp is throttled.
     * Both are written without touching `updated_at`, which is the heartbeat
     * `claim()` uses to detect a dead worker: bumping it here would make a
     * genuinely stranded download look alive and block its reclaim.
     *
     * The count is deliveries, not downloads: a local-disk response answers
     * each byte-range request separately, while a bucket redirect is one hit
     * per download. It is a popularity signal for retention, not a meter.
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
     * Pruning has to ask, because `path` is shared: a 720p and a 1080p variant
     * of the same YouTube video both land on itag 18 and reference one object.
     * Deleting the file with the first row would break the second.
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
