<?php

namespace App\Http\Middleware;

use App\Api\Exceptions\ApiException;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects tokens belonging to a suspended tenant.
 *
 * Renders as `unauthorized` rather than a media-semantics code: reporting
 * "private_or_restricted" for a billing suspension sends the client down the
 * wrong branch and makes the fault impossible to diagnose.
 */
class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user();

        if (! $tenant instanceof Tenant) {
            throw ApiException::unauthorized();
        }

        if (! $tenant->isActive()) {
            throw ApiException::unauthorized('This tenant is suspended.');
        }

        return $next($request);
    }
}
