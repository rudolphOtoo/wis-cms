<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement('CREATE INDEX IF NOT EXISTS attendance_records_session_id_index ON attendance_records (session_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS attendance_records_member_id_index ON attendance_records (member_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS attendance_records_child_id_index   ON attendance_records (child_id)');
        // Composite for adult_count / children_count and stats aggregation.
        DB::statement('CREATE INDEX IF NOT EXISTS ar_session_present_idx ON attendance_records (session_id, is_present)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendance_records_session_id_index');
        DB::statement('DROP INDEX IF EXISTS attendance_records_member_id_index');
        DB::statement('DROP INDEX IF EXISTS attendance_records_child_id_index');
        DB::statement('DROP INDEX IF EXISTS ar_session_present_idx');
    }
};
