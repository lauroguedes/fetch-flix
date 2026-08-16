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
 * Suspension is an account condition, so it renders as `unauthorized` rather
 * than reusing one of the media-semantics codes. Telling a client that a video
 * is "private_or_restricted" when the real cause is a billing suspension would
 * send it down the wrong branch and make the fault impossible to diagnose.
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
