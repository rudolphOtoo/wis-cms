<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BirthdayController;
use App\Http\Controllers\Api\CellController;
use App\Http\Controllers\Api\ChildrenController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\KeepAliveController;
use App\Http\Controllers\Api\LifeEventController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberSubmissionController;
use App\Http\Controllers\Api\MemberSubmissionWebhookController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PastoralNoteController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\ServiceReminderController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SystemAlertController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VisitorController;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

// Every domain model uses UUID primary keys. Constrain route params to
// valid UUIDs so empty/invalid values (e.g. "/api/cells/" from a client)
// get a clean 404 instead of reaching Postgres with "invalid input syntax
// for type uuid" and crashing with a 500.
Route::pattern('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('memberId', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('childId', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('serviceTypeId', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth-password-reset');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:auth-password-reset');
});

// ─────────────────────────────────────────────────────────────────
// PUBLIC WEBHOOKS (no Sanctum auth — uses shared secret instead)
//
// Rate-limited by the named 'intake-webhook' limiter defined in
// AppServiceProvider::configureRateLimiters():
//   • 5 requests per IP per 10 minutes
//   • 3 requests per phone number per day
//
// A human filling a form once needs exactly 1 hit. The limiter
// is intentionally strict — a 429 here is far less costly than
// a flooded submissions queue.
// ─────────────────────────────────────────────────────────────────
Route::post('/webhooks/member-submission',
    [MemberSubmissionWebhookController::class, 'store']
)->middleware('throttle:intake-webhook');

// ─────────────────────────────────────────────────────────────────
// PAYMENT WEBHOOKS (no Sanctum — verified via Paystack HMAC signature)
//
// Rate-limited to prevent abuse. A human paying once needs exactly
// 1 hit; Paystack retries webhook deliveries automatically.
// ─────────────────────────────────────────────────────────────────
Route::post('/webhooks/payments/paystack',
    [PaymentWebhookController::class, 'handle']
)->middleware('throttle:payment-webhook');

Route::middleware(['auth:sanctum', EnsurePasswordChanged::class])->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
        Route::post('keep-alive', KeepAliveController::class);
    });

    // App bootstrap: authenticated user + active diocese profile (key,
    // label, capabilities, strings). The frontend stores this to drive
    // branding and capability flags without extra calls.
    Route::get('bootstrap', [AuthController::class, 'bootstrap']);

    Route::get('dashboard', [DashboardController::class, 'index']);

    // SYSTEM ALERTS
    Route::get('system-alerts', [SystemAlertController::class, 'index']);
    Route::post('system-alerts/{id}/acknowledge', [SystemAlertController::class, 'acknowledge']);

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
    Route::middleware('permission:edit members')->post('members/{id}/mark-deceased', [MemberController::class, 'markDeceased']);
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

    // Cells (classes) — a member belongs to exactly one cell.
    Route::middleware('permission:view cells')->group(function () {
        Route::get('cells', [CellController::class, 'index']);
        Route::get('cells/{id}', [CellController::class, 'show']);
    });
    Route::middleware('permission:create cells')->post('cells', [CellController::class, 'store']);
    Route::middleware('permission:edit cells')->put('cells/{id}', [CellController::class, 'update']);
    Route::middleware('permission:delete cells')->delete('cells/{id}', [CellController::class, 'destroy']);
    Route::middleware('permission:manage cell members')->group(function () {
        Route::post('cells/{id}/members/{memberId}', [CellController::class, 'assignMember']);
        Route::delete('cells/{id}/members/{memberId}', [CellController::class, 'unassignMember']);
        Route::post('cells/{id}/children/{childId}', [CellController::class, 'assignChild']);
        Route::delete('cells/{id}/children/{childId}', [CellController::class, 'unassignChild']);
    });
    Route::middleware('permission:message own department')->post('departments/{id}/message', [DepartmentController::class, 'message']);
    Route::middleware('permission:message own cell')->post('cells/{id}/message', [CellController::class, 'message']);
    Route::middleware('permission:manage department members')->group(function () {
        Route::post('departments/{id}/members', [DepartmentController::class, 'addMember']);
        Route::delete('departments/{id}/members/{memberId}', [DepartmentController::class, 'removeMember']);
    });

    // ATTENDANCE
    Route::middleware('permission:view attendance')->group(function () {
        Route::get('attendance/stats', [AttendanceController::class, 'stats']);
        Route::get('attendance/sundays', [AttendanceController::class, 'sundays']);
        Route::get('attendance/service-types', [AttendanceController::class, 'serviceTypes']);
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/sessions/{id}', [AttendanceController::class, 'showSession']);
    });
    Route::middleware('permission:create attendance')->group(function () {
        Route::post('attendance/sessions', [AttendanceController::class, 'createSession']);
        Route::post('attendance/sessions/{id}/mark', [AttendanceController::class, 'markAttendance']);
        Route::post('attendance/sessions/{id}/headcount', [AttendanceController::class, 'markHeadcount']);
    });

    // FINANCE
    Route::middleware('permission:view finance')->group(function () {
        Route::get('finance/stats', [FinanceController::class, 'stats']);
        Route::get('finance/categories', [FinanceController::class, 'categories']);
        Route::get('finance/transactions', [FinanceController::class, 'index']);
        Route::get('finance/transactions/export', [FinanceController::class, 'export'])->middleware('permission:export finance');
        Route::get('finance/reports/ledger', [FinanceController::class, 'ledger'])->middleware('permission:export finance');
        Route::get('finance/transactions/{id}', [FinanceController::class, 'show']);

        // REPORTS — read-only aggregations for council monthly review.
        // Each report is a discrete endpoint; aggregation logic lives
        // in ReportsController. Same 'view finance' permission as other
        // finance read endpoints. New reports get added to this group.
        Route::get('reports/finance/income-by-category', [ReportsController::class, 'incomeByCategory']);
        Route::get('reports/finance/expense-by-category', [ReportsController::class, 'expenseByCategory']);
        Route::get('reports/attendance/trends', [ReportsController::class, 'attendanceTrends']);
        Route::get('reports/attendance/summary', [ReportsController::class, 'attendanceSummary']);
        Route::get('reports/members/welfare', [ReportsController::class, 'memberWelfare']);
        Route::get('reports/cells/comparison', [ReportsController::class, 'cellComparison']);
        Route::get('reports/life-events/year', [ReportsController::class, 'lifeEventsYear']);

        // Report exports — PDF + XLSX downloads (gated by 'export reports')
        Route::get('reports/finance/income-by-category/export-pdf', [ReportsController::class, 'incomeByCategoryPdf'])->middleware('permission:export reports');
        Route::get('reports/finance/income-by-category/export-xlsx', [ReportsController::class, 'incomeByCategoryXlsx'])->middleware('permission:export reports');
        Route::get('reports/finance/expense-by-category/export-pdf', [ReportsController::class, 'expenseByCategoryPdf'])->middleware('permission:export reports');
        Route::get('reports/finance/expense-by-category/export-xlsx', [ReportsController::class, 'expenseByCategoryXlsx'])->middleware('permission:export reports');
        Route::get('reports/attendance/trends/export-pdf', [ReportsController::class, 'attendanceTrendsPdf'])->middleware('permission:export reports');
        Route::get('reports/attendance/trends/export-xlsx', [ReportsController::class, 'attendanceTrendsXlsx'])->middleware('permission:export reports');
        Route::get('reports/attendance/summary/export-pdf', [ReportsController::class, 'attendanceSummaryPdf'])->middleware('permission:export reports');
        Route::get('reports/attendance/summary/export-xlsx', [ReportsController::class, 'attendanceSummaryXlsx'])->middleware('permission:export reports');
        Route::get('reports/members/welfare/export-pdf', [ReportsController::class, 'memberWelfarePdf'])->middleware('permission:export reports');
        Route::get('reports/members/welfare/export-xlsx', [ReportsController::class, 'memberWelfareXlsx'])->middleware('permission:export reports');
        Route::get('reports/cells/comparison/export-pdf', [ReportsController::class, 'cellComparisonPdf'])->middleware('permission:export reports');
        Route::get('reports/cells/comparison/export-xlsx', [ReportsController::class, 'cellComparisonXlsx'])->middleware('permission:export reports');
        Route::get('reports/life-events/year/export-pdf', [ReportsController::class, 'lifeEventsYearPdf'])->middleware('permission:export reports');
        Route::get('reports/life-events/year/export-xlsx', [ReportsController::class, 'lifeEventsYearXlsx'])->middleware('permission:export reports');

        // Birthday messages
        Route::get('birthdays/settings', [BirthdayController::class, 'showSettings'])
            ->middleware('permission:view birthday messages');
        Route::put('birthdays/settings', [BirthdayController::class, 'updateSettings'])
            ->middleware('permission:manage birthday messages');
        Route::post('birthdays/preview', [BirthdayController::class, 'preview'])
            ->middleware('permission:view birthday messages');
        Route::get('birthdays/upcoming', [BirthdayController::class, 'upcoming'])
            ->middleware('permission:view birthday messages');
        Route::get('birthdays/log', [BirthdayController::class, 'log'])
            ->middleware('permission:manage birthday messages');

        // Per-service-type SMS reminders (e.g. Sunday-service Saturday-evening
        // reminder, midweek-service Wednesday-morning reminder). Scheduled
        // command (reminders:send) does the actual sending; these endpoints
        // are the admin UI surface.
        Route::get('reminders/settings', [ServiceReminderController::class, 'index'])
            ->middleware('permission:view service reminders');
        Route::put('reminders/settings/{serviceTypeId}', [ServiceReminderController::class, 'upsert'])
            ->middleware('permission:manage service reminders');
        Route::post('reminders/preview', [ServiceReminderController::class, 'preview'])
            ->middleware('permission:view service reminders');
        Route::get('reminders/upcoming', [ServiceReminderController::class, 'upcoming'])
            ->middleware('permission:view service reminders');
        Route::get('reminders/log', [ServiceReminderController::class, 'log'])
            ->middleware('permission:manage service reminders');

        // Member submissions queue — admin review of self-service form
        // responses. Each submission must be explicitly approved before it
        // becomes a Member (and thus eligible for SMS dispatch).
        Route::get('submissions', [MemberSubmissionController::class, 'index'])
            ->middleware('permission:view member submissions');
        Route::get('submissions/{id}', [MemberSubmissionController::class, 'show'])
            ->middleware('permission:view member submissions');
        Route::post('submissions/{id}/approve', [MemberSubmissionController::class, 'approve'])
            ->middleware('permission:manage member submissions');
        Route::post('submissions/{id}/reject', [MemberSubmissionController::class, 'reject'])
            ->middleware('permission:manage member submissions');
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

        // Member linking — for the User <-> Member architecture.
        Route::post('users/{id}/link-member', [UserController::class, 'linkMember']);
        Route::post('users/{id}/create-and-link', [UserController::class, 'createAndLinkMember']);
        Route::delete('users/{id}/link-member', [UserController::class, 'unlinkMember']);

        // Branch-level system settings: follow-up SMS templates, delay, toggle.
        // Same admin gate as user management ('manage users').
        Route::get('settings/follow-up', [SettingsController::class, 'show']);
        Route::put('settings/follow-up', [SettingsController::class, 'updateFollowUp']);

        // Promote a Member to leadership of a specific cell or department.
        // Atomic: creates User + assigns role + links member + sets unit leader.
        Route::post('members/{id}/promote-to-leader', [MemberController::class, 'promoteToLeader']);
        Route::post('members/{id}/create-login', [MemberController::class, 'createLogin']);
    });

    // AUDIT
    Route::middleware('permission:view audit log')->group(function () {
        Route::get('audit', [AuditController::class, 'index']);
    });

    // PASTORAL NOTES
    Route::middleware('permission:view pastoral notes')->group(function () {
        Route::get('pastoral-notes', [PastoralNoteController::class, 'index']);
        Route::get('pastoral-notes/follow-ups', [PastoralNoteController::class, 'followUps']);
    });
    Route::middleware('permission:create pastoral notes')->group(function () {
        Route::post('pastoral-notes', [PastoralNoteController::class, 'store']);
    });
    Route::middleware('permission:update pastoral notes')->group(function () {
        Route::put('pastoral-notes/{id}', [PastoralNoteController::class, 'update']);
    });
    Route::middleware('permission:delete pastoral notes')->group(function () {
        // Pastors/admins only — gated by PastoralNotePolicy::delete
        Route::delete('pastoral-notes/{id}', [PastoralNoteController::class, 'destroy']);
    });

    // LIFE EVENTS — recorded deaths (linked to a member, marks them
    // 'deceased') and births (baby + mother details) for year-end review.
    Route::middleware('permission:view life events')->group(function () {
        Route::get('life-events/stats', [LifeEventController::class, 'stats']);
        Route::get('life-events', [LifeEventController::class, 'index']);
        Route::get('life-events/{id}', [LifeEventController::class, 'show']);
    });
    Route::middleware('permission:manage life events')->group(function () {
        Route::post('life-events', [LifeEventController::class, 'store']);
        Route::put('life-events/{id}', [LifeEventController::class, 'update']);
        Route::delete('life-events/{id}', [LifeEventController::class, 'destroy']);
    });

    // ONLINE PAYMENTS
    Route::post('payments/initialize', [PaymentController::class, 'store']);
    Route::get('payments/verify/{reference}', [PaymentController::class, 'verify']);
    Route::middleware('permission:view payments')->group(function () {
        Route::get('payments/history', [PaymentController::class, 'index']);
        Route::get('payments/stats', [PaymentController::class, 'stats']);
    });

    // MEMBER PORTAL — scoped to the authenticated member's own data
    Route::middleware('permission:access portal')->prefix('portal')->group(function () {
        Route::get('profile', [PortalController::class, 'profile']);
        Route::get('giving', [PortalController::class, 'giving']);
        Route::get('attendance', [PortalController::class, 'attendance']);
    });
});
