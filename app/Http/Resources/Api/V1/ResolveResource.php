<?php

namespace App\Http\Resources\Api\V1;

use App\Api\RequestId;
use App\Media\Data\ResolvedMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The success envelope for /resolve.
 *
 * @property ResolvedMedia $resource
 */
class ResolveResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Every contracted key is always present. `bytes` is emitted as null rather
     * than omitted when yt-dlp reports no size at all: a client reading a fixed
     * shape must not have a key disappear underneath it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->resource->media;

        return [
            'status' => 'ok',
            'provider' => $this->resource->provider,
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'author' => $this->resource->author,
            'duration_ms' => $this->resource->durationMs,
            'thumbnail_url' => $this->resource->thumbnailUrl,
            'media' => [
                // Stored root-relative so nothing host-dependent reaches the
                // shared cache; made absolute here against the host actually
                // serving this request. Already-absolute values pass through
                // `url()` untouched.
                'url' => url($media->url),
                'mime_type' => $media->mimeType,
                'container' => $media->container,
                'bytes' => $media->bytes,
                'width' => $media->width,
                'height' => $media->height,
                'has_audio' => $media->hasAudio,
                'has_video' => $media->hasVideo,
                // Verbatim: some CDNs 403 without an exact Referer, and the
                // client feeds these straight into URLSession.
                'headers' => (object) $media->headers,
                'expires_at' => $media->expiresAt->toIso8601ZuluString(),

                // Additive, outside the original contract. Since itag 22 was
                // removed a 720p request routinely lands on 360p; these say so
                // rather than leaving the client to infer it.
                'degraded' => $media->degraded,
                'requested_height' => $media->requestedHeight,
            ],
            'request_id' => RequestId::current(),
        ];
    }
}
