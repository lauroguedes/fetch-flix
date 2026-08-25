<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Media\Storage\AssetDelivery;
use App\Media\Storage\AssetKey;
use App\Media\Storage\MediaAssetRepository;
use App\Models\MediaAsset;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a resolved media file.
 *
 * The bytes come from us, so the caller's address is irrelevant — unlike a
 * provider URL, which is bound to the IP that extracted it.
 *
 * Unauthenticated by design: the client hands this URL to a plain downloader
 * that sends no headers, so the signature is the credential.
 */
class StreamController
{
    public function __construct(
        private readonly MediaAssetRepository $assets,
        private readonly AssetDelivery $delivery,
    ) {}

    /**
     * Download a resolved media file
     *
     * Do not build this URL. Take `media.url` from a POST /v1/resolve response
     * and use it exactly as given — `expires` and `signature` are computed by
     * this service and the signature is the only credential, so no token is
     * needed and no header is read. That is what lets you hand the URL to a
     * plain downloader or a video player.
     *
     * For the same reason this endpoint cannot be exercised from this page:
     * "Try it" sends no valid signature and will answer 403.
     *
     * Answers 302 to a short-lived storage URL when media lives on a bucket, or
     * streams the bytes with range support when it is on local disk. A 410
     * means the link expired and you should resolve again; a 403 means the
     * signature does not match.
     */
    public function __invoke(string $asset): Response
    {
        $media = $this->lookup($asset);

        // Only hit by the first caller for a given video; everyone after that,
        // across every tenant, is served from the disk.
        $media = $this->awaitDownload($media);

        if ($media->status->isInFlight()) {
            throw ApiException::resolverUnavailable(
                retryAfter: 5,
                message: 'This media is still being prepared. Please retry shortly.',
            );
        }

        if (! $media->isServable()) {
            throw $this->recordedFailure($media);
        }

        $this->assets->touchAccess($media);

        return $this->delivery->respond($media);
    }

    private function lookup(string $asset): MediaAsset
    {
        try {
            $key = AssetKey::fromString($asset);
        } catch (InvalidArgumentException) {
            throw ApiException::unauthorized('This download link is not valid.');
        }

        $media = $this->assets->findByKey($key);

        if ($media === null) {
            // A correctly signed link to a pruned asset. `expired` is the code
            // that tells the client to re-resolve.
            throw ApiException::expired('This download is no longer available. Please resolve again.');
        }

        return $media;
    }

    /**
     * Hold the connection while the download finishes.
     *
     * Bounded and short: every waiting request occupies a PHP worker. Past the
     * bound the client retries, and the download carries on regardless.
     */
    private function awaitDownload(MediaAsset $media): MediaAsset
    {
        $waitMs = (int) config('media.storage.wait_ms', 20000);
        $pollMs = max(1, (int) config('media.storage.poll_ms', 250));

        $deadline = microtime(true) + ($waitMs / 1000);

        while ($media->status->isInFlight() && microtime(true) < $deadline) {
            usleep($pollMs * 1000);
            $media->refresh();
        }

        return $media;
    }

    /**
     * Report the failure the download actually hit, not a generic one.
     *
     * A private or removed video stays non-retryable here exactly as on
     * resolve; flattening everything into `download_failed` would have clients
     * retrying a video that will never become available.
     */
    private function recordedFailure(MediaAsset $media): ApiException
    {
        $code = $media->error_code !== null
            ? ErrorCode::tryFrom($media->error_code) ?? ErrorCode::DownloadFailed
            : ErrorCode::DownloadFailed;

        return new ApiException(
            errorCode: $code,
            retryAfter: $code->isRetryable() ? 5 : null,
        );
    }
}
