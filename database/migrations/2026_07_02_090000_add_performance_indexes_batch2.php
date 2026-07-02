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
            $table->index('cell_id', 'members_cell_id_batched_index'); // Add explicit name to avoid conflict with 2026_06_03 migration
            $table->index('status');
            $table->index('phone');
            $table->index('email');
            $table->index(['branch_id', 'status'], 'members_branch_id_status_batched_index'); // Add explicit name
            $table->index(['cell_id', 'status'], 'members_cell_id_status_batched_index');
        });

        // attendance_sessions table - critical for N+1 query fixes
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->index('department_id', 'attendance_sessions_department_id_batched_index');
            $table->index('cell_id', 'attendance_sessions_cell_id_batched_index');
            $table->index('branch_id', 'attendance_sessions_branch_id_batched_index');
            $table->index(['branch_id', 'service_date'], 'attendance_sessions_branch_date_batched_index');
            $table->index(['department_id', 'service_date'], 'attendance_sessions_department_date_batched_index');
            $table->index(['cell_id', 'service_date'], 'attendance_sessions_cell_date_batched_index');
            $table->index('follow_up_status', 'attendance_sessions_follow_up_status_batched_index');
        });

        // attendance_records table - many child lookups from sessions
        // (session_id, member_id, child_id indexes already exist from 2026_06_16_100002)
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->index(['session_id', 'member_id']);
            $table->index(['session_id', 'child_id']);
            $table->index(['is_present', 'member_id']);
            $table->index(['is_present', 'child_id']);
        });

        // finance tables - high transaction volume
        // (member_id, category_id indexes already exist from 2026_06_03_155047;
        //  branch_id+transaction_date index already exists from table creation)
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index(['member_id', 'transaction_date']);
            $table->index(['type', 'transaction_date']); // Income/expense summaries
            $table->index('currency');
        });

        // (finance_categories has no branch_id column — only type and display_order exist)
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->index('type');
            $table->index('is_active');
        });

        // department_members pivot table - many-to-many lookups
        // (department_id, member_id indexes already exist from 2026_07_01_195355)
        Schema::table('department_members', function (Blueprint $table) {
            $table->index(['department_id', 'role']); // Department roles filtering
            $table->index(['member_id', 'role']); // Member departments filtering
            $table->index('joined_at');
        });

        // cell relationships
        // (leader_user_id index already exists from 2026_06_03_155047)
        Schema::table('cells', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('is_active');
        });

        // message queues and communications
        // (sender_id, ['branch_id', 'status', 'created_at'] already exist from 2026_07_01_195355)
        Schema::table('messages', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('channel'); // SMS/email/message routing
        });

        // (message_id index already exists from 2026_07_01_195355)
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->index('member_id'); // Member message history
            $table->index(['message_id', 'delivery_status']); // Queue processing
            $table->index(['member_id', 'delivery_status']); // Member inbox
        });

        // service structures
        // (leader_user_id index already exists from 2026_06_03_155047)
        Schema::table('departments', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('is_active');
            $table->index(['branch_id', 'is_active']); // Dashboard filtering
        });

        // (service_types has no branch_id column)
        Schema::table('service_types', function (Blueprint $table) {
            $table->index('is_active');
        });

        // visitor tracking
        // (visit_date index already exists from 2026_07_01_195355)
        Schema::table('visitors', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index(['visit_date', 'follow_up_status']); // Follow-up pipeline
            $table->index('phone'); // Visitor lookup
        });

        // children table (related to member relationships)
        // (guardian_member_id, class_group indexes already exist from 2026_07_01_195355)
        Schema::table('children', function (Blueprint $table) {
            $table->index('branch_id');
            $table->index('is_active');
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
        // (activity_log has no branch_id column — subject_type and causer_type+subject_id/causer_id
        //  composites already exist from table creation)
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('causer_id'); // User action history
        });

        // Spatie permission tables (essential for RBAC performance)
        // (model_type+model_uuid composite already indexed from Spatie migration)
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->index('role_id');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
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
            $table->dropIndex('causer_id');
        });

        Schema::table('member_submissions', function (Blueprint $table) {
            $table->dropIndex('email');
            $table->dropIndex(['phone', 'branch_id']);
            $table->dropIndex(['branch_id', 'status', 'submitted_at']);
            $table->dropIndex('status');
            $table->dropIndex('branch_id');
        });

        Schema::table('children', function (Blueprint $table) {
            $table->dropIndex('is_active');
            $table->dropIndex('branch_id');
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropIndex(['visit_date', 'follow_up_status']);
            $table->dropIndex('phone');
            $table->dropIndex('branch_id');
        });

        Schema::table('service_types', function (Blueprint $table) {
            $table->dropIndex('is_active');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'is_active']);
            $table->dropIndex('is_active');
            $table->dropIndex('branch_id');
        });

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropIndex(['member_id', 'delivery_status']);
            $table->dropIndex(['message_id', 'delivery_status']);
            $table->dropIndex('member_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('channel');
            $table->dropIndex('branch_id');
        });

        Schema::table('cells', function (Blueprint $table) {
            $table->dropIndex('is_active');
            $table->dropIndex('branch_id');
        });

        Schema::table('department_members', function (Blueprint $table) {
            $table->dropIndex(['member_id', 'role']);
            $table->dropIndex(['department_id', 'role']);
            $table->dropIndex('joined_at');
        });

        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropIndex('type');
            $table->dropIndex('is_active');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('currency');
            $table->dropIndex(['type', 'transaction_date']);
            $table->dropIndex(['member_id', 'transaction_date']);
            $table->dropIndex('branch_id');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['is_present', 'child_id']);
            $table->dropIndex(['is_present', 'member_id']);
            $table->dropIndex(['session_id', 'child_id']);
            $table->dropIndex(['session_id', 'member_id']);
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropIndex('attendance_sessions_department_id_batched_index');
            $table->dropIndex('attendance_sessions_cell_id_batched_index');
            $table->dropIndex('attendance_sessions_branch_id_batched_index');
            $table->dropIndex('attendance_sessions_branch_date_batched_index');
            $table->dropIndex('attendance_sessions_department_date_batched_index');
            $table->dropIndex('attendance_sessions_cell_date_batched_index');
            $table->dropIndex('attendance_sessions_follow_up_status_batched_index');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex('members_cell_id_batched_index');
            $table->dropIndex('members_branch_id_status_batched_index');
            $table->dropIndex('members_cell_id_status_batched_index');
            $table->dropIndex('status');
            $table->dropIndex('email');
            $table->dropIndex('phone');
            $table->dropIndex('branch_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email', 'branch_id']);
            $table->dropIndex('is_active');
            $table->dropIndex('branch_id');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropIndex('permission_id');
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropIndex('role_id');
        });

        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->dropIndex('role_id');
            $table->dropIndex('permission_id');
        });
    }
};
