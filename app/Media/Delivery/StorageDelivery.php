<?php

namespace App\Media\Delivery;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\Data\MediaStream;
use App\Media\Data\ResolvedMedia;
use App\Media\Jobs\DownloadMediaAsset;
use App\Media\Storage\AssetKey;
use App\Media\Storage\MediaAssetRepository;
use App\Media\Storage\SignedStreamUrl;
use App\Models\MediaAsset;
use Throwable;

/**
 * Serves the media from our own storage, behind our own signed URL.
 *
 * The reason this exists: a provider stream URL carries the IP that extracted
 * it and is signed against it. A server resolves, a phone on a different
 * network downloads, and the CDN answers 403. That is not fixable with headers
 * or retries — only by holding the bytes ourselves.
 *
 * What the client sees does not change shape. `media.url` is still a URL,
 * `media.headers` is still an object, `media.expires_at` is still a timestamp.
 * The difference is that all three are now ours: no IP binding, an expiry we
 * choose and can reissue, and no headers required at all.
 */
final class StorageDelivery implements MediaDelivery
{
    public function __construct(
        private readonly MediaAssetRepository $assets,
        private readonly SignedStreamUrl $urls,
    ) {}

    /**
     * Answer from a file we already hold, without extracting.
     *
     * The saving is the whole point of keying assets on the request rather than
     * on the chosen format: an 8s yt-dlp process becomes one indexed read, for
     * every tenant, for the life of the file.
     */
    public function cached(string $provider, string $providerId, MediaRequest $request): ?ResolvedMedia
    {
        $asset = $this->assets->findByKey(
            AssetKey::for($provider, $providerId, $request->maxHeight, $request->audioOnly)
        );

        if ($asset === null || ! $asset->isServable()) {
            return null;
        }

        // The stored file is shared across tenants, but the limits are not.
        // They have to be re-applied here or a warm hit would quietly hand a
        // tenant something its own configuration forbids.
        $this->assertWithinLimits($asset, $request);

        return $this->envelope($asset, $request);
    }

    /**
     * Register what was chosen, queue the transfer, and hand back a URL of ours.
     *
     * The URL is returned before the file exists. It has to be: the client
     * calls resolve synchronously with a 12s ceiling and a transfer does not
     * fit in that. The delivery endpoint absorbs the gap, and only ever for the
     * first caller of a given video.
     */
    public function prepare(ResolvedMedia $resolved, MediaRequest $request): ResolvedMedia
    {
        $stream = $resolved->media;

        if ($stream->formatId === null) {
            // Without a format id a queued download cannot pin itself to the
            // stream this response just described, and might store something
            // else. Falling back to the provider URL is worse than it sounds
            // but better than storing the wrong file.
            return $resolved;
        }

        $key = AssetKey::for($resolved->provider, $resolved->id, $request->maxHeight, $request->audioOnly);

        $asset = $this->assets->register($key, [
            'provider' => $resolved->provider,
            'provider_video_id' => $resolved->id,
            'max_height' => $request->maxHeight,
            'audio_only' => $request->audioOnly,
            'format_id' => $stream->formatId,
            'mime_type' => $stream->mimeType,
            'container' => $stream->container,
            'bytes' => $stream->bytes,
            'width' => $stream->width,
            'height' => $stream->height,
            'has_audio' => $stream->hasAudio,
            'has_video' => $stream->hasVideo,
            'duration_ms' => $resolved->durationMs,
            'title' => $resolved->title,
            'author' => $resolved->author,
            'thumbnail_url' => $resolved->thumbnailUrl,
        ]);

        if (! $asset->isServable()) {
            try {
                // Idempotent by the job's unique id and, more importantly, by
                // the conditional claim on the row itself.
                DownloadMediaAsset::dispatch($asset->asset_key, $request->url, $stream->formatId);
            } catch (Throwable $e) {
                // A queue that is down must not turn a good resolve into an
                // error. The client already has a working URL; the row is still
                // pending, so the next resolve for this variant dispatches
                // again and the delivery endpoint reports honestly until then.
                report($e);
            }
        }

        return $this->envelope($asset, $request);
    }

    /**
     * Rebuild the contracted envelope around a signed URL of ours.
     */
    private function envelope(MediaAsset $asset, MediaRequest $request): ResolvedMedia
    {
        $signed = $this->urls->mint($asset->asset_key);

        $height = $asset->height;
        $degraded = $height !== null && $height < $request->maxHeight;

        return new ResolvedMedia(
            provider: $asset->provider,
            id: $asset->provider_video_id,
            title: (string) $asset->title,
            author: $asset->author,
            durationMs: $asset->duration_ms,
            thumbnailUrl: $asset->thumbnail_url,
            media: new MediaStream(
                url: $signed['url'],
                mimeType: $asset->mime_type ?? 'application/octet-stream',
                container: $asset->container ?? 'mp4',

                // Exact once the file has landed; the extractor's estimate
                // before that. Both are better than the null the contract
                // tolerates.
                bytes: $asset->bytes,

                width: $asset->width,
                height: $height,
                hasAudio: $asset->has_audio,
                hasVideo: $asset->has_video,

                // Nothing to pass through: our own URL needs no Referer and no
                // User-Agent. The key stays in the response as an empty object
                // because the contract says it is always present.
                headers: [],

                expiresAt: $signed['expires_at'],
                degraded: $degraded,
                requestedHeight: $degraded ? $request->maxHeight : null,
                formatId: $asset->format_id,
            ),
        );
    }

    /**
     * Re-apply the caller's own ceilings to a shared stored file.
     */
    private function assertWithinLimits(MediaAsset $asset, MediaRequest $request): void
    {
        if ($request->maxDurationMs !== null
            && $asset->duration_ms !== null
            && $asset->duration_ms > $request->maxDurationMs) {
            throw ApiException::tooLong();
        }

        if ($request->maxBytes !== null && $asset->bytes !== null && $asset->bytes > $request->maxBytes) {
            throw ApiException::tooLarge();
        }

        if ($request->minProgressiveHeight > 0
            && ($asset->height ?? 0) < $request->minProgressiveHeight) {
            throw ApiException::mediaMissing(
                'No progressive stream meets the minimum acceptable quality.'
            );
        }
    }
}
