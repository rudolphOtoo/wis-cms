<?php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\VisitorController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\FinanceController;
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

    // Departments
    Route::get('departments/stats',                      [DepartmentController::class, 'stats']);
    Route::get('departments/{id}/members',               [DepartmentController::class, 'departmentMembers']);
    Route::post('departments/{id}/members',              [DepartmentController::class, 'addMember']);
    Route::delete('departments/{id}/members/{memberId}', [DepartmentController::class, 'removeMember']);
    Route::apiResource('departments', DepartmentController::class);

    // Attendance
    Route::get('attendance/stats',              [AttendanceController::class, 'stats']);
    Route::get('attendance/service-types',      [AttendanceController::class, 'serviceTypes']);
    Route::get('attendance',                    [AttendanceController::class, 'index']);
    Route::post('attendance/sessions',          [AttendanceController::class, 'createSession']);
    Route::get('attendance/sessions/{id}',      [AttendanceController::class, 'showSession']);
    Route::post('attendance/sessions/{id}/mark',[AttendanceController::class, 'markAttendance']);

    // Finance
    Route::get('finance/stats',                      [FinanceController::class, 'stats']);
    Route::get('finance/categories',                 [FinanceController::class, 'categories']);
    Route::get('finance/transactions',               [FinanceController::class, 'index']);
    Route::post('finance/transactions',              [FinanceController::class, 'store']);
    Route::get('finance/transactions/{id}',          [FinanceController::class, 'show']);
    Route::put('finance/transactions/{id}',          [FinanceController::class, 'update']);
    Route::delete('finance/transactions/{id}',       [FinanceController::class, 'destroy']);
});
