<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-03: Add missing indexes to attendance_records.
 *
 * PostgreSQL does NOT auto-create indexes for foreign key columns
 * (unlike MySQL). The attendance_records table had zero indexes on its
 * three FK columns, meaning every attendance read was a full sequential
 * scan. At a congregation of 500+ members across multiple sessions,
 * this is the single most expensive unindexed table in the system.
 *
 * Indexes added:
 *
 *   session_id               — used in EVERY session lookup
 *   member_id                — used by portal attendance, adult_count counts
 *   child_id                 — used by children attendance queries
 *   (session_id, is_present) — composite for COUNT WHERE is_present = TRUE,
 *                              used by adult_count / children_count accessors
 *                              and AttendanceStatsService aggregation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->index('session_id');
            $table->index('member_id');
            $table->index('child_id');
            // The hot path for adult_count / children_count and stats queries.
            $table->index(['session_id', 'is_present'], 'ar_session_present_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->dropIndex(['member_id']);
            $table->dropIndex(['child_id']);
            $table->dropIndex('ar_session_present_idx');
        });
    }
};
