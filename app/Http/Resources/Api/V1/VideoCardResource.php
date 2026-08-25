<?php

namespace App\Http\Resources\Api\V1;

use App\Media\Data\VideoCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in a discovery feed.
 *
 * @property VideoCard $resource
 */
class VideoCardResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Provider-native id. Stable, but not something you have to parse:
            // use `url` below.
            'id' => $this->resource->id,
            // Which site this came from, and which URL shape `url` follows.
            'provider' => $this->resource->provider,
            // Feed this straight to POST /v1/resolve. It exists so a consumer
            // never has to know how to turn an id back into a URL.
            'url' => $this->resource->url,
            // Video title, already decoded — the provider returns it with
            // HTML entities escaped.
            'title' => $this->resource->title,
            // Name of the uploading channel.
            'channel' => $this->resource->channel,
            // Length in milliseconds. Null when the provider reported none.
            'duration_ms' => $this->resource->durationMs,
            // Best still the provider offers. Null when it offers none.
            'thumbnail_url' => $this->resource->thumbnailUrl,
            // Pixel dimensions of the video, never null: size your player
            // frame from these before any media loads, or every portrait clip
            // renders into a landscape box.
            'width' => $this->resource->width,
            // Pixel height. Greater than `width` for portrait/Shorts content.
            'height' => $this->resource->height,
        ];
    }
}
