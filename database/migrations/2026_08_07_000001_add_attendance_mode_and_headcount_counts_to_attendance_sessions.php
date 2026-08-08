<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance dual-mode (DIOCESE profiles).
 *
 * Two recording workflows share the attendance_sessions table:
 *
 *   register   (default, WIS): a per-person AttendanceRecord row is created
 *              for each member/child. Counts derive from attendance_records.
 *
 *   headcount  (mcgh diocese): ushers tally at the door — male_count /
 *              female_count / children_count stored directly on the session.
 *              Sessions are church-wide (no cell_id / department_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->string('attendance_mode')->default('register')->after('notes');
            $table->unsignedInteger('male_count')->nullable()->after('attendance_mode');
            $table->unsignedInteger('female_count')->nullable()->after('male_count');
            $table->unsignedInteger('children_count')->nullable()->after('female_count');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropColumn(['attendance_mode', 'male_count', 'female_count', 'children_count']);
        });
    }
};
