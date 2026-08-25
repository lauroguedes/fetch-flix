<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Exceptions\ApiException;
use App\Http\Requests\Api\V1\DiscoverRequest;
use App\Http\Resources\Api\V1\DiscoverResource;
use App\Media\Data\DiscoveryQuery;
use App\Media\Discovery\DiscoveryService;
use App\Media\ProviderRegistry;
use App\Models\Tenant;
use App\Models\VideoCategory;

class DiscoverController
{
    public function __construct(
        private readonly DiscoveryService $discovery,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * Browse or search short-form videos
     *
     * Returns a page of video cards, each carrying the `url` you pass straight
     * to POST /v1/resolve — you never have to build a provider URL yourself.
     *
     * Two modes, chosen with `kind`: `category` reads an operator-curated feed
     * (slugs come from GET /v1/categories), `search` runs a free-text query.
     *
     * Results are cached and shared across all consumers, because the upstream
     * search costs 100 units of a daily budget the whole deployment shares.
     * Expect a repeated query to be served from cache, and expect a 503 with
     * `code=quota_exhausted` once the day's budget is gone.
     *
     * `width` and `height` are always present so a client can size a player
     * frame before anything loads.
     */
    public function __invoke(DiscoverRequest $request): DiscoverResource
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        // A deployment that serves no provider says so, rather than returning
        // an empty list that looks like "no results". A missing API key is
        // reported separately, by the client that needs it.
        if ($this->providers->isEmpty()) {
            throw ApiException::unresolvable('Discovery is not available on this deployment.');
        }

        $query = $request->input('kind') === 'category'
            ? DiscoveryQuery::category(
                $this->category((string) $request->input('category')),
                $request->maxDurationMs(),
                $request->pageToken(),
            )
            : DiscoveryQuery::search(
                (string) $request->input('query'),
                $request->maxDurationMs(),
                $request->pageToken(),
            );

        return new DiscoverResource($this->discovery->discover($tenant, $query));
    }

    private function category(string $slug): VideoCategory
    {
        $category = VideoCategory::query()->where('slug', $slug)->where('is_active', true)->first();

        if ($category === null) {
            throw ApiException::unresolvable('No such category.');
        }

        return $category;
    }
}
