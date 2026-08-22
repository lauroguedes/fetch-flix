<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Media\Storage\AssetDelivery;
use App\Media\Storage\AssetKey;
use App\Media\Storage\MediaAssetRepository;
use App\Models\MediaAsset;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a resolved media file.
 *
 * This is the endpoint that fixes the problem the whole storage change exists
 * for: the provider's own URL is bound to the IP that extracted it, so a server
 * resolving and a phone downloading is a guaranteed 403. Here the bytes come
 * from us and the caller's address is irrelevant.
 *
 * Unauthenticated by design. The signature is the credential, because the
 * client hands this URL to a plain downloader that sends no headers of its own.
 */
class StreamController extends Controller
{
    public function __construct(
        private readonly MediaAssetRepository $assets,
        private readonly AssetDelivery $delivery,
    ) {}

    public function __invoke(string $asset): Response
    {
        $media = $this->lookup($asset);

        // Only ever hit by the first caller for a given video: the resolve that
        // minted this URL queued the download moments ago. Everyone after that,
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
            // A correctly signed link to an asset that has since been pruned.
            // Re-resolving is exactly the right move and `expired` is the code
            // that tells the client to do it.
            throw ApiException::expired('This download is no longer available. Please resolve again.');
        }

        return $media;
    }

    /**
     * Hold the connection while the download finishes.
     *
     * Bounded, and kept short, because every waiting request occupies a PHP
     * worker. Past the bound the client is told to retry rather than being
     * strung along: the download carries on regardless and the retry lands on a
     * stored file.
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
     * A private or removed video must stay non-retryable here, exactly as it is
     * on resolve. Flattening every stored failure into `download_failed` would
     * have clients retrying a video that will never become available.
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
