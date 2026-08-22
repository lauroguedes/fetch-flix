<?php

namespace App\Media\Jobs;

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Media\Data\AssetStatus;
use App\Media\Storage\AssetKey;
use App\Media\Storage\AssetPathBuilder;
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
 * Runs on a queue because it cannot run anywhere else: the client's resolve
 * call has a 12s ceiling and a transfer takes longer than that on all but the
 * shortest clips. The resolve responds immediately with a signed URL and this
 * job makes that URL answer with bytes.
 *
 * Mutual exclusion is the row's, not the queue's. `ShouldBeUnique` only
 * collapses a dispatch storm; the guarantee that one file is downloaded once
 * comes from the conditional update in `claim()`, which holds even across
 * workers on different hosts and even if the unique lock has expired.
 */
final class DownloadMediaAsset implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    public function __construct(
        private readonly string $assetKey,
        /**
         * The page URL, carried in the job payload rather than stored on the
         * asset row. It is transient by nature and disappears with the job.
         */
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
        AssetPathBuilder $paths,
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
            // Someone else holds it, or it has exhausted its attempts. Either
            // way there is nothing useful for this worker to do.
            return;
        }

        $temporary = $this->temporaryPath($asset);

        try {
            $file = $downloader->download(
                sourceUrl: $this->sourceUrl,
                formatId: $this->formatId,
                destination: $temporary,
                // The tenant's own max_bytes was already enforced at resolve
                // time, against the size the extractor declared. This is the
                // deployment-wide ceiling, and it applies to a file that is
                // shared by every tenant.
                maxBytes: (int) config('media.storage.max_bytes', 524_288_000),
            );

            $this->store($assets, $paths, $asset, $file);
        } catch (YtDlpFailed $e) {
            $this->fail($assets, $asset, $classifier->classify($e));
        } catch (ApiException $e) {
            $this->fail($assets, $asset, $e);
        } finally {
            // A temporary file left behind on every failure would fill the disk
            // long before the assets themselves did.
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Stream the finished file onto the media disk.
     *
     * The path is derived here rather than read off the row, so the recorded
     * location always describes the file this job actually wrote. The resolve
     * records what format was chosen; this records where it landed.
     */
    private function store(
        MediaAssetRepository $assets,
        AssetPathBuilder $paths,
        MediaAsset $asset,
        DownloadedFile $file,
    ): void {
        $disk = (string) config('media.storage.disk', 'media');

        $path = $paths->build(
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
            // writeStream, never put: on S3 this becomes a multipart upload, so
            // a 500MB file never has to fit in PHP's memory limit.
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
     * The row is marked failed *before* rethrowing so the next queue attempt
     * finds a claimable row. Leaving it in `downloading` would make the retry a
     * silent no-op until the stale-claim window expired.
     */
    private function fail(MediaAssetRepository $assets, MediaAsset $asset, ApiException $e): void
    {
        $assets->markFailed($asset, $e->errorCode);

        if (! $e->errorCode->isRetryable()) {
            // Private, removed, region-blocked, too large: retrying spends the
            // remaining attempts to reach the same answer.
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
