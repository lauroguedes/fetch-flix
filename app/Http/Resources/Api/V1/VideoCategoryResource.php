<?php

namespace App\Http\Resources\Api\V1;

use App\Models\VideoCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One curated feed.
 *
 * @mixin VideoCategory
 *
 * @property VideoCategory $resource
 */
class VideoCategoryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Pass this as `category` to POST /v1/discover.
            'slug' => $this->resource->slug,
            // Display text, safe to render in a client's UI.
            'label' => $this->resource->label,
        ];
    }
}
