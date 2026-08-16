<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResolveRequest;
use App\Http\Resources\Api\V1\ResolveResource;
use App\Media\Data\MediaRequest;
use App\Media\ResolveService;
use App\Models\Tenant;

class ResolveController extends Controller
{
    public function __construct(
        private readonly ResolveService $resolver,
    ) {}

    public function __invoke(ResolveRequest $request): ResolveResource
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        $mediaRequest = MediaRequest::fromTenant(
            tenant: $tenant,
            url: $request->mediaUrl(),
            maxHeight: $request->maxHeight(),
            audioOnly: $request->audioOnly(),
            maxDurationMs: $request->maxDurationMs(),
        );

        $resolved = $this->resolver->resolve($tenant, $mediaRequest, RequestId::current());

        return new ResolveResource($resolved);
    }
}
