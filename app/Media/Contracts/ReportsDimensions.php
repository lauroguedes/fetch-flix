<?php

namespace App\Media\Contracts;

use App\Media\Data\Dimensions;

interface ReportsDimensions extends Provider
{
    /**
     * Null means genuinely unknown, and callers must have a fallback.
     *
     * Consumers size a player frame before anything loads and short-form video
     * is 9:16, so a null that reaches the response renders every portrait clip
     * into a landscape box. Returning null here is allowed; returning it from
     * the API is not.
     */
    public function dimensionsFor(string $id): ?Dimensions;
}
