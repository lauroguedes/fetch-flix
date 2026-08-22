<?php

namespace App\Http\Middleware;

use App\Api\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signature is the authentication on the delivery endpoint.
 *
 * There is no bearer token here: the client hands this URL to URLSession, which
 * sends nothing of its own. Holding a correctly signed URL is the whole
 * credential, which is why they are short-lived and the asset key in them is a
 * hash rather than a video id.
 *
 * Laravel's own `signed` middleware would answer 403 for both a tampered URL
 * and a merely expired one. They mean opposite things to a client: expired is
 * "re-resolve, this works fine", tampered is "stop". Telling them apart is the
 * entire reason this exists rather than the framework's version.
 */
class ValidateStreamSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (URL::hasValidSignature($request)) {
            return $next($request);
        }

        if (URL::hasCorrectSignature($request) && ! URL::signatureHasNotExpired($request)) {
            // 410, and deliberately not retryable: retrying the same URL will
            // never work. The client's contract for this code is to resolve
            // again, which mints a fresh one.
            throw ApiException::expired('This download link has expired. Please resolve again.');
        }

        throw ApiException::unauthorized('This download link is not valid.');
    }
}
