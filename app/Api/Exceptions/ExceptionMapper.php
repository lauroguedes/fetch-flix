<?php

namespace App\Api\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Translates any exception reaching the API surface into the one envelope
 * consumers know how to parse.
 *
 * One mapper rather than per-type render callbacks, because Laravel rewrites
 * some exceptions before those run: an AuthorizationException arrives here
 * already converted to an AccessDeniedHttpException.
 */
final class ExceptionMapper
{
    public static function map(Throwable $e): ApiException
    {
        if ($e instanceof ApiException) {
            return $e;
        }

        if ($e instanceof ValidationException) {
            return ApiException::invalidRequest(
                collect($e->errors())->flatten()->first() ?? $e->getMessage()
            );
        }

        if ($e instanceof AuthenticationException) {
            return ApiException::unauthorized();
        }

        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return ApiException::unauthorized('The token lacks the required scope.');
        }

        if ($e instanceof ThrottleRequestsException) {
            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return ApiException::rateLimited($retryAfter > 0 ? $retryAfter : 60);
        }

        if ($e instanceof NotFoundHttpException || $e instanceof MethodNotAllowedHttpException) {
            return ApiException::unresolvable('No such endpoint.');
        }

        // Any other 4xx is permanent; a 5xx would be retried forever.
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return ApiException::invalidRequest($e->getMessage() ?: null);
        }

        // An unhandled failure here is nearly always a broken extractor or a
        // transient upstream change, so it renders as a retryable 503 rather
        // than a 500 telling the client to give up. The cause is still reported.
        report($e);

        return ApiException::resolverUnavailable(previous: $e);
    }
}
