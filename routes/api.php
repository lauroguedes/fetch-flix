<?php

use App\Http\Controllers\Api\V1\DiscoverController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ResolveController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| The version lives in the URI prefix. The `client.api_version` field carried
| in request bodies is recorded for telemetry only and never branches
| behaviour, so a consumer cannot pin itself to old semantics by lying.
|
| Endpoints are added by phase: /resolve and /discover follow.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('request-id')->group(function () {
    // Unauthenticated: this is an operational probe for monitoring, and it must
    // stay reachable when token issuance or the database is the thing that is
    // broken. It exposes no tenant data.
    Route::get('/health', HealthController::class)->name('health');

    Route::middleware(['auth:sanctum', 'tenant.active', 'usage'])->group(function () {
        Route::post('/resolve', ResolveController::class)
            ->middleware(['abilities:resolve', 'throttle:resolve'])
            ->name('resolve');

        Route::post('/discover', DiscoverController::class)
            ->middleware(['abilities:discover', 'throttle:discover'])
            ->name('discover');
    });
});
