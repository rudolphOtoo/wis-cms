<?php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\VisitorController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('logout',          [AuthController::class, 'logout']);
        Route::get('me',               [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    Route::get('dashboard', [DashboardController::class, 'index']);

    // Members
    Route::get('members/stats', [MemberController::class, 'stats']);
    Route::apiResource('members', MemberController::class);

    // Visitors
    Route::get('visitors/stats', [VisitorController::class, 'stats']);
    Route::apiResource('visitors', VisitorController::class);
});
