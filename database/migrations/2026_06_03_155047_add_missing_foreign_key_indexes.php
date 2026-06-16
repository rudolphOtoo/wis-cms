<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add indexes to foreign-key columns that PostgreSQL does not
 * index automatically. Without these, queries that filter or
 * join on these columns perform sequential scans, which is fine
 * at dev scale (60 members) but degrades quickly as data grows.
 *
 * SURFACED BY AI codebase analysis (Antigravity) + direct
 * pg_indexes inspection: 9 FK columns across 6 tables had no
 * supporting index.
 *
 * No data changes; index creation only. Safe to roll back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->index('leader_user_id', 'cells_leader_user_id_index');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->index('leader_user_id', 'departments_leader_user_id_index');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->index('cell_id', 'members_cell_id_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('recorded_by', 'transactions_recorded_by_index');
            $table->index('category_id', 'transactions_category_id_index');
            $table->index('member_id', 'transactions_member_id_index');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->index('member_id', 'attendance_records_member_id_index');
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->index('cell_id', 'attendance_sessions_cell_id_index');
            $table->index('department_id', 'attendance_sessions_department_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->dropIndex('cells_leader_user_id_index');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex('departments_leader_user_id_index');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex('members_cell_id_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_recorded_by_index');
            $table->dropIndex('transactions_category_id_index');
            $table->dropIndex('transactions_member_id_index');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex('attendance_records_member_id_index');
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropIndex('attendance_sessions_cell_id_index');
            $table->dropIndex('attendance_sessions_department_id_index');
        });
    }
};
