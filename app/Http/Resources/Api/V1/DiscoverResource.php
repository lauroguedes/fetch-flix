<?php

namespace App\Http\Resources\Api\V1;

use App\Media\Data\DiscoveryPage;
use App\Media\Data\VideoCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DiscoveryPage $resource
 */
class DiscoverResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'videos' => array_map(
                fn (VideoCard $video) => [
                    'id' => $video->id,
                    'title' => $video->title,
                    'channel' => $video->channel,
                    'duration_ms' => $video->durationMs,
                    'thumbnail_url' => $video->thumbnailUrl,
                    // Never null. A consumer sizes its player frame from these
                    // before any media loads.
                    'width' => $video->width,
                    'height' => $video->height,
                ],
                $this->resource->videos
            ),
            'next_page_token' => $this->resource->nextPageToken,
        ];
    }
}
