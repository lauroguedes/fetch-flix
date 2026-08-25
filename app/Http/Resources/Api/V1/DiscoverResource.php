<?php

namespace App\Http\Resources\Api\V1;

use App\Media\Data\DiscoveryPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The success envelope for /discover.
 *
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
            'videos' => VideoCardResource::collection($this->resource->videos),
            // Pass back as `page_token` for the next page. Null on the last.
            'next_page_token' => $this->resource->nextPageToken,
        ];
    }
}
