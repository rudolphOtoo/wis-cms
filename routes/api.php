<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChildrenController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VisitorController;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware(['auth:sanctum', EnsurePasswordChanged::class])->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    Route::get('dashboard', [DashboardController::class, 'index']);

    // MEMBERS
    Route::middleware('permission:view members')->group(function () {
        Route::get('members/stats', [MemberController::class, 'stats']);
        Route::get('members/{id}/giving', [MemberController::class, 'giving']);
        Route::get('members/{id}/giving-statement', [MemberController::class, 'givingStatement']);
        Route::get('members', [MemberController::class, 'index']);
        Route::get('members/export', [MemberController::class, 'export'])->middleware('permission:export members');
        Route::get('members/{id}', [MemberController::class, 'show']);
    });
    Route::middleware('permission:create members')->post('members', [MemberController::class, 'store']);
    Route::middleware('permission:edit members')->put('members/{id}', [MemberController::class, 'update']);
    Route::middleware('permission:delete members')->delete('members/{id}', [MemberController::class, 'destroy']);

    // VISITORS
    Route::middleware('permission:view visitors')->group(function () {
        Route::get('visitors/stats', [VisitorController::class, 'stats']);
        Route::get('visitors', [VisitorController::class, 'index']);
        Route::get('visitors/{id}', [VisitorController::class, 'show']);
    });
    Route::middleware('permission:create visitors')->group(function () {
        Route::post('visitors', [VisitorController::class, 'store']);
        Route::post('visitors/{id}/convert', [VisitorController::class, 'convertToMember']);
    });
    Route::middleware('permission:edit visitors')->put('visitors/{id}', [VisitorController::class, 'update']);
    Route::middleware('permission:delete visitors')->delete('visitors/{id}', [VisitorController::class, 'destroy']);

    // CHILDREN
    Route::middleware('permission:view children')->group(function () {
        Route::get('children/stats', [ChildrenController::class, 'stats']);
        Route::get('children', [ChildrenController::class, 'index']);
        Route::get('children/{id}', [ChildrenController::class, 'show']);
    });
    Route::middleware('permission:create children')->post('children', [ChildrenController::class, 'store']);
    Route::middleware('permission:edit children')->put('children/{id}', [ChildrenController::class, 'update']);
    Route::middleware('permission:delete children')->delete('children/{id}', [ChildrenController::class, 'destroy']);

    // DEPARTMENTS
    Route::middleware('permission:view departments')->group(function () {
        Route::get('departments/stats', [DepartmentController::class, 'stats']);
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::get('departments/{id}', [DepartmentController::class, 'show']);
        Route::get('departments/{id}/members', [DepartmentController::class, 'departmentMembers']);
    });
    Route::middleware('permission:create departments')->post('departments', [DepartmentController::class, 'store']);
    Route::middleware('permission:edit departments')->put('departments/{id}', [DepartmentController::class, 'update']);
    Route::middleware('permission:delete departments')->delete('departments/{id}', [DepartmentController::class, 'destroy']);
    Route::middleware('permission:manage department members')->group(function () {
        Route::post('departments/{id}/members', [DepartmentController::class, 'addMember']);
        Route::delete('departments/{id}/members/{memberId}', [DepartmentController::class, 'removeMember']);
    });

    // ATTENDANCE
    Route::middleware('permission:view attendance')->group(function () {
        Route::get('attendance/stats', [AttendanceController::class, 'stats']);
        Route::get('attendance/service-types', [AttendanceController::class, 'serviceTypes']);
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/sessions/{id}', [AttendanceController::class, 'showSession']);
    });
    Route::middleware('permission:create attendance')->group(function () {
        Route::post('attendance/sessions', [AttendanceController::class, 'createSession']);
        Route::post('attendance/sessions/{id}/mark', [AttendanceController::class, 'markAttendance']);
    });

    // FINANCE
    Route::middleware('permission:view finance')->group(function () {
        Route::get('finance/stats', [FinanceController::class, 'stats']);
        Route::get('finance/categories', [FinanceController::class, 'categories']);
        Route::get('finance/transactions', [FinanceController::class, 'index']);
        Route::get('finance/transactions/{id}', [FinanceController::class, 'show']);
    });
    Route::middleware('permission:create transactions')->post('finance/transactions', [FinanceController::class, 'store']);
    Route::middleware('permission:edit transactions')->put('finance/transactions/{id}', [FinanceController::class, 'update']);
    Route::middleware('permission:delete transactions')->delete('finance/transactions/{id}', [FinanceController::class, 'destroy']);

    // MESSAGES
    Route::middleware('permission:view messages')->group(function () {
        Route::get('messages/stats', [MessageController::class, 'stats']);
        Route::get('messages', [MessageController::class, 'index']);
        Route::get('messages/{id}', [MessageController::class, 'show']);
    });
    Route::middleware('permission:send messages')->group(function () {
        Route::post('messages/recipient-count', [MessageController::class, 'recipientCount']);
        Route::post('messages/send', [MessageController::class, 'send']);
    });

    // USERS
    Route::middleware('permission:manage users')->group(function () {
        Route::get('users/roles', [UserController::class, 'roles']);
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{id}', [UserController::class, 'show']);
        Route::put('users/{id}', [UserController::class, 'update']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);
    });

    // AUDIT
    Route::middleware('permission:view audit log')->group(function () {
        Route::get('audit', [AuditController::class, 'index']);
    });

    // MEMBER PORTAL — scoped to the authenticated member's own data
    Route::middleware('permission:access portal')->prefix('portal')->group(function () {
        Route::get('profile', [PortalController::class, 'profile']);
        Route::get('giving', [PortalController::class, 'giving']);
        Route::get('attendance', [PortalController::class, 'attendance']);
    });
});
