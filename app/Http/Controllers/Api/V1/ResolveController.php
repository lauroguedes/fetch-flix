<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\RequestId;
use App\Http\Requests\Api\V1\ResolveRequest;
use App\Http\Resources\Api\V1\ResolveResource;
use App\Media\Data\MediaRequest;
use App\Media\ResolveService;
use App\Models\Tenant;

class ResolveController
{
    public function __construct(
        private readonly ResolveService $resolver,
    ) {}

    /**
     * Resolve a video URL into a downloadable file
     *
     * Give it a video page URL; get back the metadata plus a signed `media.url`
     * on this service that streams the actual bytes.
     *
     * The call is synchronous and answers within roughly 12 seconds. The bytes
     * are served from our own storage rather than the provider, because a
     * provider stream URL is signed against the IP that extracted it — a phone
     * on another network would get a 403 from the CDN.
     *
     * `media.url` is short-lived and single-purpose: hand it to a downloader or
     * a player as-is, never rebuild it. If it expires (410), call this endpoint
     * again for a fresh one.
     *
     * Send the same `X-Request-Id` when retrying one logical action: a repeat
     * replays the stored response instead of paying for a second extraction.
     */
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
