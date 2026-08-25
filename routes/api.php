<?php

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DiscoverController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ResolveController;
use App\Http\Controllers\Api\V1\StreamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| The version lives in the URI prefix, and nowhere else: nothing in a request
| body or header selects behaviour, so a consumer cannot pin itself to old
| semantics by lying about what it is.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('request-id')->group(function () {
    // Unauthenticated: an operational probe must stay reachable when token
    // issuance or the database is what is broken. Exposes no tenant data.
    Route::get('/health', HealthController::class)
        ->middleware('throttle:health')
        ->name('health');

    // Unauthenticated on purpose: the client hands this URL to a plain
    // downloader that sends no headers, so the signature is the credential.
    // Short-lived, and its asset key is a hash rather than a video id.
    Route::get('/stream/{asset}', StreamController::class)
        ->middleware(['throttle:stream', 'stream-signature'])
        ->name('stream');

    Route::middleware(['auth:sanctum', 'tenant.active', 'usage'])->group(function () {
        Route::post('/resolve', ResolveController::class)
            ->middleware(['abilities:resolve', 'throttle:resolve'])
            ->name('resolve');

        Route::post('/discover', DiscoverController::class)
            ->middleware(['abilities:discover', 'throttle:discover'])
            ->name('discover');

        // Discovery validates `category` against this list, so a client that
        // cannot read it can only guess and take a 422. Gated on the same
        // ability, because the list is useful for nothing else.
        Route::get('/categories', CategoryController::class)
            ->middleware(['abilities:discover', 'throttle:categories'])
            ->name('categories');
    });
});
