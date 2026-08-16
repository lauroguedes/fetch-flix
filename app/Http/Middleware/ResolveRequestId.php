<?php

namespace App\Http\Middleware;

use App\Api\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the correlation id for the request and echoes it back.
 *
 * A missing or malformed X-Request-Id is replaced rather than rejected. The
 * header is a debugging and deduplication aid, and failing a media request over
 * its formatting would turn a client-side nicety into an outage.
 */
class ResolveRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $supplied = $request->header(RequestId::HEADER);

        $requestId = RequestId::isAcceptable($supplied)
            ? $supplied
            : (string) Str::uuid();

        RequestId::set($requestId);

        $response = $next($request);

        $response->headers->set(RequestId::HEADER, $requestId);

        return $response;
    }
}
