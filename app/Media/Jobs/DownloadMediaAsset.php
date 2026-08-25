<?php

namespace App\Media\Jobs;

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Media\Data\AssetStatus;
use App\Media\Storage\AssetKey;
use App\Media\Storage\DownloadedFile;
use App\Media\Storage\MediaAssetRepository;
use App\Media\Storage\MediaDownloader;
use App\Media\YtDlp\YtDlpErrorClassifier;
use App\Media\YtDlp\YtDlpFailed;
use App\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fetches one media file and puts it on the media disk.
 *
 * On a queue because resolve has a 12s ceiling and a transfer takes longer than
 * that on all but the shortest clips. Resolve answers with a signed URL; this
 * job makes that URL answer with bytes.
 *
 * Mutual exclusion is the row's, not the queue's: `ShouldBeUnique` only
 * collapses a dispatch storm, while `claim()`'s conditional update holds across
 * workers on different hosts even after the unique lock expires.
 */
final class DownloadMediaAsset implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    public function __construct(
        private readonly string $assetKey,
        /** The page URL, transient by nature, so it lives with the job. */
        private readonly string $sourceUrl,
        private readonly string $formatId,
    ) {
        $this->onQueue('downloads');
    }

    public function uniqueId(): string
    {
        return $this->assetKey;
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function timeout(): int
    {
        // Room for the transfer itself plus the upload to the media disk.
        return (int) config('media.storage.download_timeout', 300) + 120;
    }

    public function handle(
        MediaAssetRepository $assets,
        MediaDownloader $downloader,
        YtDlpErrorClassifier $classifier,
    ): void {
        $asset = $assets->findByKey(AssetKey::fromString($this->assetKey));

        if ($asset === null || $asset->status === AssetStatus::Stored) {
            return;
        }

        if (! $assets->claim(
            $asset,
            maxAttempts: (int) config('media.storage.max_attempts', 3),
            staleAfter: (int) config('media.storage.claim_stale_after', 900),
        )) {
            // Held elsewhere, or out of attempts. Nothing to do.
            return;
        }

        $temporary = $this->temporaryPath($asset);

        try {
            $file = $downloader->download(
                sourceUrl: $this->sourceUrl,
                formatId: $this->formatId,
                destination: $temporary,
                // The tenant's max_bytes was enforced at resolve time. This is
                // the deployment-wide ceiling on a file shared by all tenants.
                maxBytes: (int) config('media.storage.max_bytes', 524_288_000),
            );

            $this->store($assets, $asset, $file);
        } catch (YtDlpFailed $e) {
            $this->fail($assets, $asset, $classifier->classify($e));
        } catch (ApiException $e) {
            $this->fail($assets, $asset, $e);
        } finally {
            // Left behind on every failure, these fill the disk long before
            // the assets do.
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Stream the finished file onto the media disk.
     *
     * The path is derived here, not read off the row, so the recorded location
     * always describes the file this job actually wrote.
     */
    private function store(
        MediaAssetRepository $assets,
        MediaAsset $asset,
        DownloadedFile $file,
    ): void {
        $disk = (string) config('media.storage.disk', 'media');

        $path = AssetKey::storagePath(
            provider: $asset->provider,
            providerVideoId: $asset->provider_video_id,
            formatId: $this->formatId,
            container: $asset->container ?? 'bin',
        );

        $handle = fopen($file->path, 'rb');

        if ($handle === false) {
            throw new YtDlpFailed(stderr: 'Downloaded file is unreadable.', exitCode: 0);
        }

        try {
            // writeStream, never put: a multipart upload on S3, so a 500MB
            // file never has to fit in PHP's memory limit.
            Storage::disk($disk)->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }

        $assets->markStored($asset, [
            'disk' => $disk,
            'path' => $path,
            'format_id' => $this->formatId,
            'bytes' => $file->bytes,
            'checksum' => $file->checksum,
        ]);
    }

    /**
     * Record the failure, and decide whether it is worth trying again.
     *
     * Marked failed *before* rethrowing so the next queue attempt finds a
     * claimable row; leaving it `downloading` makes the retry a silent no-op
     * until the stale-claim window expires.
     */
    private function fail(MediaAssetRepository $assets, MediaAsset $asset, ApiException $e): void
    {
        $assets->markFailed($asset, $e->errorCode);

        if (! $e->errorCode->isRetryable()) {
            // Private, removed, region-blocked, too large: retrying just
            // spends the remaining attempts on the same answer.
            return;
        }

        throw $e;
    }

    /**
     * Terminal failure of the job itself, such as the worker being killed.
     */
    public function failed(?Throwable $e): void
    {
        $assets = app(MediaAssetRepository::class);
        $asset = $assets->findByKey(AssetKey::fromString($this->assetKey));

        if ($asset === null || $asset->status === AssetStatus::Stored) {
            return;
        }

        $assets->markFailed(
            $asset,
            $e instanceof ApiException ? $e->errorCode : ErrorCode::DownloadFailed,
        );
    }

    private function temporaryPath(MediaAsset $asset): string
    {
        $directory = (string) config('media.storage.tmp_dir', storage_path('app/media-tmp'));
        $extension = $asset->container !== null ? '.'.$asset->container : '.bin';

        return rtrim($directory, '/').'/'.$asset->asset_key.$extension;
    }
}
