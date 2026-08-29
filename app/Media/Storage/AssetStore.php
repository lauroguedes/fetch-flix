<?php

namespace App\Media\Storage;

use App\Media\Data\MediaRequest;
use App\Media\Data\MediaStream;
use App\Media\Data\ResolvedMedia;
use App\Media\Jobs\DownloadMediaAsset;
use App\Media\LimitGuard;
use App\Models\MediaAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The resolve path's view of stored media: answer from a file we already hold,
 * or register one and queue its transfer. Either way it hands back an envelope
 * pointing at a signed URL of ours.
 *
 * A provider stream URL is signed against the IP that extracted it, so a server
 * resolving and a phone downloading is a guaranteed 403. Holding the bytes
 * ourselves is the only fix.
 *
 * The client sees the same envelope: `media.url` is a URL, `media.headers` an
 * object, `media.expires_at` a timestamp. All three are now ours. Serving those
 * bytes is AssetDelivery's job, not this class's.
 */
final class AssetStore
{
    public function __construct(
        private readonly MediaAssetRepository $assets,
    ) {}

    /**
     * Answer from a file we already hold, without extracting.
     *
     * Turns a repeat resolve from an 8s yt-dlp process into one indexed read,
     * for every tenant, for the life of the file.
     */
    public function cached(string $provider, string $providerId, MediaRequest $request): ?ResolvedMedia
    {
        $asset = $this->assets->findByKey(
            AssetKey::for($provider, $providerId, $request->maxHeight, $request->audioOnly)
        );

        if ($asset === null || ! $asset->isServable()) {
            return null;
        }

        $envelope = $this->envelope($asset, $request);

        // The file is shared across tenants but the limits are not, so they are
        // re-applied here. Same guard the cache hits in `ResolveService` use:
        // one file, one set of rules, whichever path reached it.
        LimitGuard::assert($request, $envelope);

        return $envelope;
    }

    /**
     * Register what was chosen, queue the transfer, hand back a URL of ours.
     *
     * The URL is returned before the file exists: resolve has a 12s ceiling and
     * a transfer does not fit in it. The delivery endpoint absorbs the gap, and
     * only for the first caller of a given video.
     */
    public function prepare(ResolvedMedia $resolved, MediaRequest $request): ResolvedMedia
    {
        $stream = $resolved->media;

        if ($stream->formatId === null) {
            // Without a format id the queued download cannot pin itself to the
            // stream this response described, and might store something else.
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
                DownloadMediaAsset::dispatch($asset->asset_key, $request->url, $stream->formatId);
            } catch (Throwable $e) {
                // A queue that is down must not turn a good resolve into an
                // error. The row stays pending, so the next resolve dispatches
                // again and delivery reports honestly until then.
                report($e);
            }
        }

        return $this->envelope($asset, $request);
    }

    /**
     * Our own signed route, never the bucket's pre-signed URL: local and
     * production behave identically, switching storage provider does not change
     * the contract, and the quoted expiry stays ours to reissue.
     *
     * Signed root-relative, and deliberately so. An absolute signature covers
     * the scheme and host, which are not knowable everywhere this runs: a queue
     * worker has no request and falls back to `app.url`, and the envelope it
     * writes is then served from the cross-tenant cache to real requests. Any
     * drift between the two — http behind an https proxy is the usual one —
     * makes every download 403 for the life of the cache entry. A relative
     * signature covers only the path and query, so there is nothing to drift.
     *
     * `ResolveResource` makes it absolute against whatever host is serving.
     *
     * @return array{url: string, expires_at: Carbon}
     */
    public function signedUrl(string $assetKey): array
    {
        $expiresAt = Carbon::now()->addSeconds((int) config('media.storage.signed_ttl', 21600));

        return [
            'url' => URL::temporarySignedRoute('api.v1.stream', $expiresAt, ['asset' => $assetKey], absolute: false),
            'expires_at' => $expiresAt,
        ];
    }

    private function envelope(MediaAsset $asset, MediaRequest $request): ResolvedMedia
    {
        $signed = $this->signedUrl($asset->asset_key);

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

                // Exact once the file has landed, the extractor's estimate
                // before that.
                bytes: $asset->bytes,

                width: $asset->width,
                height: $height,
                hasAudio: $asset->has_audio,
                hasVideo: $asset->has_video,

                // Our own URL needs no Referer and no User-Agent. The key stays
                // as an empty object because the contract says it is present.
                headers: [],

                expiresAt: $signed['expires_at'],
                degraded: $degraded,
                requestedHeight: $degraded ? $request->maxHeight : null,
                formatId: $asset->format_id,
            ),
        );
    }
}
