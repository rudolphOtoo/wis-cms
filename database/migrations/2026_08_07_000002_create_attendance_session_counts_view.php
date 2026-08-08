<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * attendance_session_counts — per-session attendance aggregate view.
 *
 * One row per attendance_sessions row, exposing the counts BOTH recording
 * modes need in a single shape. Report/stats queries join this view instead
 * of duplicating the conditional SQL for register vs headcount:
 *
 *   - register:  counts are derived from attendance_records
 *                (present, non-soft-deleted rows only).
 *   - headcount: counts are the stored male/female/children tallies.
 *
 *   adult_count   register: present members       headcount: male + female
 *   children_count register: present children     headcount: children
 *   total_count   register: present (members + children)
 *                            headcount: male + female + children
 *   male_count    register: NULL                  headcount: male
 *   female_count  register: NULL                  headcount: female
 *   records_total register: ALL records (present + absent, non-deleted)
 *                            headcount: same as total_count
 *     (drives attendance_rate in the trends report: headcount always records
 *      the people present, so rate is 100%; register preserves present/total.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW attendance_session_counts AS
            SELECT
                s.id                                                       AS session_id,
                s.branch_id,
                s.service_date,
                s.service_type_id,
                s.cell_id,
                s.department_id,
                s.attendance_mode,
                CASE
                    WHEN s.attendance_mode = 'headcount'
                        THEN COALESCE(s.male_count, 0) + COALESCE(s.female_count, 0)
                    ELSE COUNT(ar.id) FILTER (
                        WHERE ar.is_present = true AND ar.deleted_at IS NULL AND ar.member_id IS NOT NULL)
                END                                                        AS adult_count,
                CASE
                    WHEN s.attendance_mode = 'headcount'
                        THEN COALESCE(s.children_count, 0)
                    ELSE COUNT(ar.id) FILTER (
                        WHERE ar.is_present = true AND ar.deleted_at IS NULL AND ar.child_id IS NOT NULL)
                END                                                        AS children_count,
                CASE
                    WHEN s.attendance_mode = 'headcount' THEN COALESCE(s.male_count, 0)
                    ELSE NULL
                END                                                        AS male_count,
                CASE
                    WHEN s.attendance_mode = 'headcount' THEN COALESCE(s.female_count, 0)
                    ELSE NULL
                END                                                        AS female_count,
                CASE
                    WHEN s.attendance_mode = 'headcount'
                        THEN COALESCE(s.male_count, 0) + COALESCE(s.female_count, 0) + COALESCE(s.children_count, 0)
                    ELSE COUNT(ar.id) FILTER (
                        WHERE ar.is_present = true AND ar.deleted_at IS NULL)
                END                                                        AS total_count,
                CASE
                    WHEN s.attendance_mode = 'headcount'
                        THEN COALESCE(s.male_count, 0) + COALESCE(s.female_count, 0) + COALESCE(s.children_count, 0)
                    ELSE COUNT(ar.id) FILTER (WHERE ar.deleted_at IS NULL)
                END                                                        AS records_total
            FROM attendance_sessions s
            LEFT JOIN attendance_records ar ON ar.session_id = s.id
            GROUP BY
                s.id, s.branch_id, s.service_date, s.service_type_id,
                s.cell_id, s.department_id, s.attendance_mode,
                s.male_count, s.female_count, s.children_count
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS attendance_session_counts');
    }
};
