<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The OpenAPI hook decorates operations from tables keyed by literal route
 * paths. Every lookup in it is a silent miss: rename a route and the endpoint
 * quietly acquires the wrong documented errors, loses its success description
 * and loses its headers, with nothing failing. This is what fails instead.
 */
it('documents only paths that are still routed', function () {
    $reflection = new ReflectionClass(AppServiceProvider::class);

    $documented = collect(['ERRORS_BY_PATH', 'SUCCESS_RESPONSES'])
        ->flatMap(fn (string $table) => array_keys((array) $reflection->getConstant($table)))
        ->merge((array) $reflection->getConstant('TENANT_PATHS'))
        ->unique()
        ->values();

    $routed = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => '/'.ltrim(Str::after($route->uri(), 'api/'), '/'))
        ->unique();

    expect($documented)->not->toBeEmpty()
        ->and($documented->diff($routed)->all())->toBe([]);
});
