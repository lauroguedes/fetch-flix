<?php

namespace App\Media;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\Data\ResolvedMedia;

/**
 * A site this service can resolve media from.
 */
interface Provider
{
    /** Stable identifier returned to clients as `provider`. */
    public function slug(): string;

    public function claims(string $url): bool;

    /**
     * Extract the provider-native id from a URL it claims.
     *
     * @throws ApiException when the URL is claimed but malformed
     */
    public function normalizeId(string $url): string;

    /**
     * @throws ApiException
     */
    public function resolve(MediaRequest $request): ResolvedMedia;
}
