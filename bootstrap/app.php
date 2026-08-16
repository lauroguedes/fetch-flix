<?php

use App\Api\Exceptions\ExceptionMapper;
use App\Api\RequestId;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\RecordApiUsage;
use App\Http\Middleware\ResolveRequestId;
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

            // Token abilities act as endpoint scopes, so a discovery-only
            // consumer cannot spend resolver capacity.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // Never redirect an unauthenticated API caller to a login page.
        //
        // Laravel's Authenticate middleware resolves route('login') for any
        // request that does not look like JSON, and this application has no
        // such route, so the RouteNotFoundException that follows was surfacing
        // as a retryable 503 instead of a permanent 403. A client with a bad
        // token would have retried it forever.
        //
        // Returning null keeps the AuthenticationException intact so it maps to
        // `unauthorized` regardless of what Accept header the caller sent.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One callback, one envelope. See ExceptionMapper for why the mapping is
        // centralised rather than split across per-type render callbacks.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // A response that was already built is final. Laravel uses this to
            // carry rate-limiter and abort() responses, and re-mapping one would
            // discard a correct 429 into a generic 503.
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
