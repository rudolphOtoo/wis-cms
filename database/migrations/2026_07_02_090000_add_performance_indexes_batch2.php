<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // N+1/Query & Index improvements for commonly filtered/foreign-key columns

        // users table - frequently used in membership filtering
        Schema::table('users', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('is_active');
            $table->index(['email', 'branch_id']); // email lookup + branch filtering
        });

        // members table - many N+1 sites, high activity
        Schema::table('members', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('cell_id');
            $table->index('status');
            $table->index('is_active');
            $table->index('phone');
            $table->index('email');
            $table->index(['branch_id', 'status']); // Dashboard department-specific lookups
            $table->index(['cell_id', 'is_active']); // Cell leader dashboards
        });

        // attendance_sessions table - critical for N+1 query fixes
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->index('department_id');
            $table->index('cell_id');
            $table->index('branch_id');
            $table->index(['branch_id', 'service_date']); // Dashboard filtering
            $table->index(['department_id', 'service_date']);
            $table->index(['cell_id', 'service_date']);
            $table->index('follow_up_status');
        });

        // attendance_records table - many child lookups from sessions
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->index('session_id');
            $table->index('member_id');
            $table->index('child_id');
            $table->index(['session_id', 'member_id']);
            $table->index(['session_id', 'child_id']);
            $table->index(['is_present', 'member_id']);
            $table->index(['is_present', 'child_id']);
        });

        // finance tables - high transaction volume
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('member_id');
            $table->index('category_id');
            $table->index('branch_id');
            $table->index(['branch_id', 'transaction_date']); // Financial reports
            $table->index(['member_id', 'transaction_date']);
            $table->index(['type', 'transaction_date']); // Income/expense summaries
            $table->index('currency');
        });

        Schema::table('finance_categories', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('type');
            $table->index('is_active');
            $table->index(['branch_id', 'type', 'display_order']); // Category ordering
        });

        // department_members pivot table - many-to-many lookups
        Schema::table('department_members', function (Blueprint $table) {
            $table->index('department_id');
            $table->index('member_id');
            $table->index(['department_id', 'role']); // Department roles filtering
            $table->index(['member_id', 'role']); // Member departments filtering
            $table->index('joined_at');
        });

        // cell relationships
        Schema::table('cells', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('leader_user_id');
            $table->index('is_active');
        });

        // message queues and communications
        Schema::table('messages', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('sender_id');
            $table->index(['branch_id', 'status', 'created_at']); // Admin message history
            $table->index('channel'); // SMS/email/message routing
        });

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->index('message_id');
            $table->index('member_id'); // Member message history
            $table->index(['message_id', 'delivery_status']); // Queue processing
            $table->index(['member_id', 'delivery_status']); // Member inbox
        });

        // service structures
        Schema::table('departments', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('leader_user_id');
            $table->index('is_active');
            $table->index(['branch_id', 'is_active']); // Dashboard filtering
        });

        Schema::table('service_types', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('is_active');
        });

        // visitor tracking
        Schema::table('visitors', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('visit_date'); // Daily reporting
            $table->index(['visit_date', 'follow_up_status']); // Follow-up pipeline
            $table->index('phone'); // Visitor lookup
        });

        // children table (related to member relationships)
        Schema::table('children', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('guardian_member_id'); // Member kids filtering
            $table->index('is_active');
            $table->index('class_group'); // Programs/reports
        });

        // submissions and approvals
        Schema::table('member_submissions', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('status'); // Review queue
            $table->index(['phone', 'branch_id']); // Duplicate detection
            $table->index(['branch_id', 'status', 'submitted_at']); // Review analytics
            $table->index('email');
        });

        // activity logs and auditing
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('subject_type', 'subject_type'); // Polymorphic lookups
            $table->index('causer_id'); // User action history
            $table->index(['branch_id', 'log_name', 'created_at']); // Console actions
        });

        // Spatie permission tables (essential for RBAC performance)
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->index(['model_type', 'model_id']); // Permission checks
            $table->index('role_id');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->index(['model_type', 'model_id']);
            $table->index('permission_id');
        });

        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->index('permission_id');
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        // Undo all created indexes - order matters for foreign key dependencies

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('branch_id', 'log_name', 'created_at');
            $table->dropIndex('causer_id');
            $table->dropIndex('subject_type', 'subject_type');
        });

        Schema::table('member_submissions', function (Blueprint $table) {
            $table->dropIndex('phone', 'branch_id');
            $table->dropIndex(['branch_id', 'status', 'submitted_at']);
            $table->dropIndex('email');
            $table->dropIndex(['branch_id', 'status']);
        });

        Schema::table('children', function (Blueprint $table) {
            $table->dropIndex('class_group');
            $table->dropIndex('guardian_member_id');
            $table->dropIndex('is_active');
            $table->dropIndex('branch_id');
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropIndex(['visit_date', 'follow_up_status']);
            $table->dropIndex('visit_date');
            $table->dropIndex('follow_up_status');
            $table->dropIndex('phone');
            $table->dropIndex('branch_id');
        });

        Schema::table('service_types', function (Blueprint $table) {
            $table->dropIndex('is_active');
            $table->dropIndex('branch_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'is_active']);
            $table->dropIndex('is_active');
            $table->dropIndex('leader_user_id');
            $table->dropIndex('branch_id');
        });

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropIndex(['member_id', 'delivery_status']);
            $table->dropIndex(['message_id', 'delivery_status']);
            $table->dropIndex('member_id');
            $table->dropIndex('message_id');
            $table->dropIndex('delivery_status');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status', 'created_at']);
            $table->dropIndex('sender_id');
            $table->dropIndex('channel');
            $table->dropIndex('branch_id');
            $table->dropIndex('status');
        });

        Schema::table('cells', function (Blueprint $table) {
            $table->dropIndex('is_active');
            $table->dropIndex('leader_user_id');
            $table->dropIndex('branch_id');
        });

        Schema::table('department_members', function (Blueprint $table) {
            $table->dropIndex(['member_id', 'role']);
            $table->dropIndex(['department_id', 'role']);
            $table->dropIndex('joined_at');
            $table->dropIndex('member_id');
            $table->dropIndex('department_id');
        });

        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'type', 'display_order']);
            $table->dropIndex('display_order');
            $table->dropIndex('type');
            $table->dropIndex('is_active');
            $table->dropIndex('branch_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('currency');
            $table->dropIndex(['type', 'transaction_date']);
            $table->dropIndex(['member_id', 'transaction_date']);
            $table->dropIndex(['branch_id', 'transaction_date']);
            $table->dropIndex('transaction_date');
            $table->dropIndex('branch_id');
            $table->dropIndex('category_id');
            $table->dropIndex('member_id');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['is_present', 'child_id']);
            $table->dropIndex(['is_present', 'member_id']);
            $table->dropIndex(['session_id', 'child_id']);
            $table->dropIndex(['session_id', 'member_id']);
            $table->dropIndex('child_id');
            $table->dropIndex('member_id');
            $table->dropIndex('session_id');
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropIndex('follow_up_status');
            $table->dropIndex(['cell_id', 'service_date']);
            $table->dropIndex(['department_id', 'service_date']);
            $table->dropIndex(['branch_id', 'service_date']);
            $table->dropIndex('service_date');
            $table->dropIndex('branch_id');
            $table->dropIndex('cell_id');
            $table->dropIndex('department_id');
            $table->dropIndex('service_type_id');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['cell_id', 'is_active']);
            $table->dropIndex(['branch_id', 'status']);
            $table->dropIndex('status');
            $table->dropIndex('is_active');
            $table->dropIndex('email');
            $table->dropIndex('phone');
            $table->dropIndex('cell_id');
            $table->dropIndex('branch_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email', 'branch_id']);
            $table->dropIndex('is_active');
            $table->dropIndex('branch_id');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropIndex('permission_id');
            $table->dropIndex(['model_type', 'model_id']);
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropIndex('role_id');
            $table->dropIndex(['model_type', 'model_id']);
        });

        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->dropIndex('role_id');
            $table->dropIndex('permission_id');
        });
    }
};
