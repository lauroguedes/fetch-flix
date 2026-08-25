<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\CategoryCollection;
use App\Models\VideoCategory;

/**
 * List the curated discovery feeds this deployment serves.
 *
 * `POST /v1/discover` with `kind=category` validates its `category` against
 * this list, so without a way to read it a client can only guess and take a
 * 422.
 *
 * Deliberately uncached. It is an indexed read of a handful of operator-curated
 * rows, and a cache here once held serialised models that came back as
 * `__PHP_Incomplete_Class` — a hard 503 on an endpoint that cannot otherwise
 * fail. Nothing in this codebase caches Eloquent objects; the discovery and
 * resolve layers cache arrays for the same reason.
 */
class CategoryController
{
    /**
     * List the available discovery categories
     *
     * The curated feeds this deployment serves. Each `slug` is a legal value
     * for `category` on POST /v1/discover; `label` is display text.
     *
     * Costs no discovery quota and changes rarely — fetch it once at startup
     * rather than before every discover call.
     */
    public function __invoke(): CategoryCollection
    {
        return new CategoryCollection(VideoCategory::query()->active()->get()->all());
    }
}
