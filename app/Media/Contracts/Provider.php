<?php

namespace App\Media\Contracts;

use App\Api\Exceptions\ApiException;

/**
 * Identity and URL claiming. Every provider implements this and nothing more.
 *
 * Capabilities are separate interfaces on purpose. Providers are genuinely not
 * alike: every one can resolve, only YouTube has a metadata API behind
 * discovery, and some offer neither reliable durations nor dimensions. A single
 * fat interface would force a provider to stub methods it cannot honour, and a
 * stub that returns null or throws is a lie the caller cannot detect.
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
}
