<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DiscoverRequest;
use App\Http\Resources\Api\V1\DiscoverResource;
use App\Media\Data\DiscoveryQuery;
use App\Media\Discovery\DiscoveryService;
use App\Media\ProviderRegistry;
use App\Models\Tenant;
use App\Models\VideoCategory;

class DiscoverController extends Controller
{
    public function __construct(
        private readonly DiscoveryService $discovery,
        private readonly ProviderRegistry $registry,
    ) {}

    public function __invoke(DiscoverRequest $request): DiscoverResource
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        // Asked by capability, not by name. If a deployment enables no provider
        // with a metadata API behind it, discovery genuinely cannot be served
        // and says so rather than returning an empty list that looks like
        // "no results".
        if ($this->registry->discoverers() === []) {
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
