<?php

namespace App\Http\Middleware;

use App\Api\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signature is the authentication on the delivery endpoint: the client
 * hands this URL to URLSession, which sends nothing of its own.
 *
 * Laravel's `signed` middleware answers 403 for both a tampered URL and a
 * merely expired one, which mean opposite things here — expired is "re-resolve,
 * this works fine", tampered is "stop". Telling them apart is why this exists.
 */
class ValidateStreamSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        // Relative, matching how AssetStore mints it: the scheme and host are
        // not part of the signature, so a proxy or a queue worker cannot
        // invalidate a link merely by disagreeing about them.
        if (URL::hasValidSignature($request, absolute: false)) {
            return $next($request);
        }

        if (URL::hasCorrectSignature($request, absolute: false) && ! URL::signatureHasNotExpired($request)) {
            // 410 and not retryable: the same URL will never work again. The
            // contract for this code is to resolve, which mints a fresh one.
            throw ApiException::expired('This download link has expired. Please resolve again.');
        }

        throw ApiException::unauthorized('This download link is not valid.');
    }
}
