<?php

namespace App\Media;

use App\Api\Exceptions\ApiException;
use App\Media\Contracts\DiscoversMedia;
use App\Media\Contracts\Provider;
use App\Media\Contracts\ResolvesMedia;

/**
 * Resolves URLs to providers, and exposes providers by capability.
 *
 * Callers ask for a capability, never for a named site. That is what lets a
 * second provider be added without touching the controllers, and what stops a
 * provider appearing on a surface it cannot actually serve.
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
     * @return array<int, Provider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    public function resolverFor(string $url): ResolvesMedia
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof ResolvesMedia && $provider->claims($url)) {
                return $provider;
            }
        }

        // No provider claims it. This is permanent for this URL: retrying will
        // not make an unsupported site supported.
        throw ApiException::unresolvable('No provider supports that URL.');
    }

    /**
     * @return array<int, DiscoversMedia>
     */
    public function discoverers(): array
    {
        return array_values(array_filter(
            $this->providers,
            fn (Provider $p) => $p instanceof DiscoversMedia
        ));
    }

    public function has(string $slug): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->slug() === $slug) {
                return true;
            }
        }

        return false;
    }
}
