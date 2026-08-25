<?php

namespace App\Media;

use App\Api\Exceptions\ApiException;

/**
 * Maps a URL to the provider that claims it.
 */
final class ProviderRegistry
{
    /**
     * @param  array<int, Provider>  $providers
     */
    public function __construct(
        private readonly array $providers = [],
    ) {}

    /**
     * Whether this deployment serves any provider at all.
     *
     * The registry is built from the `enabled` flags in config/media.php, so it
     * is the one place that has already interpreted them — callers should ask
     * it rather than re-reading a named provider's config key.
     */
    public function isEmpty(): bool
    {
        return $this->providers === [];
    }

    public function forUrl(string $url): Provider
    {
        foreach ($this->providers as $provider) {
            if ($provider->claims($url)) {
                return $provider;
            }
        }

        // Permanent for this URL: retrying will not make an unsupported site
        // supported.
        throw ApiException::unresolvable('No provider supports that URL.');
    }
}
