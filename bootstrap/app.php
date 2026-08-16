<?php

use App\Api\Exceptions\ExceptionMapper;
use App\Api\RequestId;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\RecordApiUsage;
use App\Http\Middleware\ResolveRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One callback, one envelope. See ExceptionMapper for why the mapping is
        // centralised rather than split across per-type render callbacks.
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*')
                ? ExceptionMapper::map($e)->toResponse($request)
                : null;
        });

        // Correlate every reported exception with the client's request id.
        $exceptions->context(fn () => [
            RequestId::CONTEXT_KEY => RequestId::current(),
        ]);
    })->create();
