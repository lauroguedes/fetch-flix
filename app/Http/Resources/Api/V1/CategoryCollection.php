<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The success envelope for /categories.
 *
 * A ResourceCollection rather than a JsonResource because it wraps a list of
 * models rather than one: a JsonResource here cannot say what it wraps, and
 * documentation generated from it degrades to an untyped array.
 */
class CategoryCollection extends ResourceCollection
{
    public static $wrap = null;

    /** @var class-string */
    public $collects = VideoCategoryResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Ordered by the operator's `sort_order`: the ordering is the point
            // of curating them at all.
            'categories' => $this->collection,
        ];
    }
}
