<?php

use App\Diocese\Modules\Confirmations\Http\Controllers\ConfirmationController;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

/*
| Confirmations module routes.
|
| Loaded only while the module is enabled (the provider is gated on
| capabilities.modules.confirmations), so a disabled module yields a clean
| 404 for every endpoint below.
|
| `loadRoutesFrom()` does NOT inherit the framework's automatic `api` prefix
| / middleware group, so they are applied here explicitly to keep module
| endpoints identical to the core API (401 renderer matches `api/*`, the
| throttling group applies, and the global UUID route patterns work).
*/

Route::prefix('api')->middleware('api')->group(function () {
    Route::middleware(['auth:sanctum', EnsurePasswordChanged::class])
        ->prefix('confirmations')
        ->group(function () {
            Route::get('/', [ConfirmationController::class, 'index']);
            Route::post('/', [ConfirmationController::class, 'store']);
            Route::delete('/{id}', [ConfirmationController::class, 'destroy']);
        });
});
