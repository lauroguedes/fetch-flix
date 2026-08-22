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
 * Two shapes, chosen by where the file actually is:
 *
 *  - A remote disk (S3, R2) gets a 302 to a short-lived pre-signed URL. The
 *    client transfers from the bucket directly and none of the media passes
 *    through this application, which is what keeps a video service on one small
 *    web node.
 *
 *  - A local disk is streamed from the filesystem, with byte-range support,
 *    because players seek and resume and a response that ignores Range breaks
 *    scrubbing.
 *
 * The branch is on the driver rather than on `providesTemporaryUrls()`, because
 * a local disk with `serve` enabled can mint temporary URLs too. Redirecting to
 * one would send the client back through this application for a file that was
 * already on this machine.
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
                // A remote disk that cannot pre-sign would leave every byte of
                // every video flowing through this process. That is a
                // misconfiguration worth failing loudly on rather than
                // absorbing silently.
                throw ApiException::resolverUnavailable(
                    message: 'Media storage is misconfigured.',
                    context: ['disk' => $asset->disk, 'reason' => 'no_temporary_urls'],
                );
            }

            $url = $disk->temporaryUrl(
                $path,
                Carbon::now()->addSeconds((int) config('media.storage.presigned_ttl', 900)),
            );

            // no-store because the pre-signed URL is minted per download and
            // outlives nothing. A cached redirect would hand a later caller a
            // link that has already expired.
            return redirect()->away($url, Response::HTTP_FOUND, ['Cache-Control' => 'no-store']);
        }

        if (! $disk->exists($path)) {
            // The row says stored and the file is gone. Rather than serving an
            // empty response forever, put the asset back in a state a later
            // resolve will re-download, and tell this caller to resolve again.
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
