<?php

use App\Api\Exceptions\ExceptionMapper;
use App\Api\RequestId;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\RecordApiUsage;
use App\Http\Middleware\ResolveRequestId;
use App\Http\Middleware\ValidateStreamSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'request-id' => ResolveRequestId::class,
            'tenant.active' => EnsureTenantActive::class,
            'usage' => RecordApiUsage::class,
            'stream-signature' => ValidateStreamSignature::class,

            // Token abilities act as endpoint scopes, so a discovery-only
            // consumer cannot spend resolver capacity.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // Trusted proxies are set in AppServiceProvider, not here: this closure
        // runs before the config is bound, and `env()` returns null once the
        // config is cached — which would quietly leave production trusting no
        // proxy at all.

        // Never redirect an unauthenticated API caller to a login page: this
        // app has no `login` route, so Authenticate's route() lookup threw a
        // RouteNotFoundException that surfaced as a retryable 503 instead of a
        // permanent 403. Returning null keeps the AuthenticationException
        // intact whatever Accept header the caller sent.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One callback, one envelope.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // An already-built response is final: Laravel carries rate-limiter
            // and abort() responses this way, and re-mapping one would discard
            // a correct 429 into a generic 503.
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            return ExceptionMapper::map($e)->toResponse($request);
        });

        // Correlate every reported exception with the client's request id.
        $exceptions->context(fn () => [
            RequestId::CONTEXT_KEY => RequestId::current(),
        ]);
    })->create();
