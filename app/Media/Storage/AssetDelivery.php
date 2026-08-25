<?php

namespace App\Media\Storage;

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Models\MediaAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a stored asset into bytes on the wire.
 *
 *  - Remote disk (S3, R2): a 302 to a short-lived pre-signed URL, so no media
 *    passes through this application and one small web node suffices.
 *  - Local disk: streamed from the filesystem with byte-range support, because
 *    ignoring Range breaks scrubbing.
 *
 * The branch is on the driver, not `providesTemporaryUrls()`: a local disk with
 * `serve` enabled can mint temporary URLs too, and redirecting to one would
 * send the client back through this app for a file already on this machine.
 */
final class AssetDelivery
{
    public function __construct(
        private readonly MediaAssetRepository $assets,
    ) {}

    public function respond(MediaAsset $asset): Response
    {
        $disk = Storage::disk((string) $asset->disk);
        $path = (string) $asset->path;

        if (! $this->isLocal((string) $asset->disk)) {
            if (! $disk->providesTemporaryUrls()) {
                // Without pre-signing, every byte of every video would flow
                // through this process. Fail loudly.
                throw ApiException::resolverUnavailable(
                    message: 'Media storage is misconfigured.',
                    context: ['disk' => $asset->disk, 'reason' => 'no_temporary_urls'],
                );
            }

            $url = $disk->temporaryUrl(
                $path,
                Carbon::now()->addSeconds((int) config('media.storage.presigned_ttl', 900)),
            );

            // no-store: the pre-signed URL is minted per download, so a cached
            // redirect would hand a later caller an already-expired link.
            return redirect()->away($url, Response::HTTP_FOUND, ['Cache-Control' => 'no-store']);
        }

        if (! $disk->exists($path)) {
            // Row says stored, file is gone. Put it back in a state a later
            // resolve re-downloads, and tell this caller to resolve again.
            $this->assets->markFailed($asset, ErrorCode::DownloadFailed);

            throw ApiException::expired('This download is no longer available. Please resolve again.');
        }

        return response()->file($disk->path($path), [
            'Content-Type' => $asset->mime_type ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }
}
