<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * mv_biometric_attendance_daily — pre-aggregated biometric attendance by
 * branch / service date / cell / department.
 *
 * The church-wide total for a day is the SUM of these cell rows (the
 * requirement "total derived by aggregating across individual cells").
 * Reads are a single index scan instead of re-counting attendance_records.
 *
 * CONCURRENTLY refresh (see biometric:refresh-view) needs a unique index with
 * no NULL keys, so cell/department are COALESCE'd to text sentinels for the
 * key columns; the raw uuid columns are kept for joins/filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW mv_biometric_attendance_daily AS
            SELECT
                s.branch_id,
                s.service_date,
                ar.cell_id,
                ar.department_id,
                COALESCE(ar.cell_id::text, '-')       AS cell_key,
                COALESCE(ar.department_id::text, '-') AS department_key,
                COUNT(ar.id) FILTER (WHERE ar.is_present AND ar.member_id IS NOT NULL) AS adult_count,
                COUNT(ar.id) FILTER (WHERE ar.is_present AND ar.child_id IS NOT NULL)  AS children_count,
                COUNT(ar.id) FILTER (WHERE ar.is_present)                              AS total_count
            FROM attendance_sessions s
            JOIN attendance_records ar ON ar.session_id = s.id
            WHERE ar.deleted_at IS NULL
              AND ar.source = 'biometric'
              AND s.count_toward_total = true
            GROUP BY s.branch_id, s.service_date, ar.cell_id, ar.department_id
        SQL);

        // Unique key required for REFRESH ... CONCURRENTLY.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX mv_biometric_attendance_daily_unique
                ON mv_biometric_attendance_daily (branch_id, service_date, cell_key, department_key)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX mv_biometric_attendance_daily_cell_idx
                ON mv_biometric_attendance_daily (branch_id, service_date, cell_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_biometric_attendance_daily');
    }
};
