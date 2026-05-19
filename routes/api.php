<?php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\VisitorController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\FinanceController;
use Illuminate\Support\Facades\Route;

// Public — login (rate-limited at controller level)
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// All authenticated routes
Route::middleware('auth:sanctum')->group(function () {

    // Self-management — any authenticated user
    Route::prefix('auth')->group(function () {
        Route::post('logout',          [AuthController::class, 'logout']);
        Route::get('me',               [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    Route::get('dashboard', [DashboardController::class, 'index']);

    // ===== MEMBERS =====
    Route::middleware('permission:view members')->group(function () {
        Route::get('members/stats',  [MemberController::class, 'stats']);
        Route::get('members',        [MemberController::class, 'index']);
        Route::get('members/{id}',   [MemberController::class, 'show']);
    });
    Route::middleware('permission:create members')->group(function () {
        Route::post('members',       [MemberController::class, 'store']);
    });
    Route::middleware('permission:edit members')->group(function () {
        Route::put('members/{id}',   [MemberController::class, 'update']);
    });
    Route::middleware('permission:delete members')->group(function () {
        Route::delete('members/{id}',[MemberController::class, 'destroy']);
    });

    // ===== VISITORS =====
    Route::middleware('permission:view visitors')->group(function () {
        Route::get('visitors/stats', [VisitorController::class, 'stats']);
        Route::get('visitors',       [VisitorController::class, 'index']);
        Route::get('visitors/{id}',  [VisitorController::class, 'show']);
    });
    Route::middleware('permission:create visitors')->group(function () {
        Route::post('visitors',      [VisitorController::class, 'store']);
    });
    Route::middleware('permission:edit visitors')->group(function () {
        Route::put('visitors/{id}',  [VisitorController::class, 'update']);
    });
    Route::middleware('permission:delete visitors')->group(function () {
        Route::delete('visitors/{id}',[VisitorController::class, 'destroy']);
    });

    // ===== DEPARTMENTS =====
    Route::middleware('permission:view departments')->group(function () {
        Route::get('departments/stats',         [DepartmentController::class, 'stats']);
        Route::get('departments',               [DepartmentController::class, 'index']);
        Route::get('departments/{id}',          [DepartmentController::class, 'show']);
        Route::get('departments/{id}/members',  [DepartmentController::class, 'departmentMembers']);
    });
    Route::middleware('permission:create departments')->group(function () {
        Route::post('departments',              [DepartmentController::class, 'store']);
    });
    Route::middleware('permission:edit departments')->group(function () {
        Route::put('departments/{id}',          [DepartmentController::class, 'update']);
    });
    Route::middleware('permission:delete departments')->group(function () {
        Route::delete('departments/{id}',       [DepartmentController::class, 'destroy']);
    });
    Route::middleware('permission:manage department members')->group(function () {
        Route::post('departments/{id}/members',              [DepartmentController::class, 'addMember']);
        Route::delete('departments/{id}/members/{memberId}', [DepartmentController::class, 'removeMember']);
    });

    // ===== ATTENDANCE =====
    Route::middleware('permission:view attendance')->group(function () {
        Route::get('attendance/stats',         [AttendanceController::class, 'stats']);
        Route::get('attendance/service-types', [AttendanceController::class, 'serviceTypes']);
        Route::get('attendance',               [AttendanceController::class, 'index']);
        Route::get('attendance/sessions/{id}', [AttendanceController::class, 'showSession']);
    });
    Route::middleware('permission:create attendance')->group(function () {
        Route::post('attendance/sessions',             [AttendanceController::class, 'createSession']);
        Route::post('attendance/sessions/{id}/mark',   [AttendanceController::class, 'markAttendance']);
    });

    // ===== FINANCE =====
    Route::middleware('permission:view finance')->group(function () {
        Route::get('finance/stats',             [FinanceController::class, 'stats']);
        Route::get('finance/categories',        [FinanceController::class, 'categories']);
        Route::get('finance/transactions',      [FinanceController::class, 'index']);
        Route::get('finance/transactions/{id}', [FinanceController::class, 'show']);
    });
    Route::middleware('permission:create transactions')->group(function () {
        Route::post('finance/transactions',     [FinanceController::class, 'store']);
    });
    Route::middleware('permission:edit transactions')->group(function () {
        Route::put('finance/transactions/{id}', [FinanceController::class, 'update']);
    });
    Route::middleware('permission:delete transactions')->group(function () {
        Route::delete('finance/transactions/{id}', [FinanceController::class, 'destroy']);
    });
});
