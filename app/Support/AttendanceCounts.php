<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Per-session attendance counts for the report queries.
 *
 * This is the fast, index-scoped equivalent of the
 * attendance_session_counts view. The view aggregates the ENTIRE
 * attendance_records table on every read: PostgreSQL cannot push
 * predicates on the outer attendance_sessions join (branch_id,
 * service_date) through the view's GROUP BY, so every report rescans
 * every attendance row in the database — measured at 700-940 ms once
 * the table grows to ~280k rows.
 *
 * This class emits a LATERAL subquery computed per session. Because
 * the aggregation is driven by the outer session row (WHERE
 * ar.session_id = s.id), the planner walks only the records that
 * belong to the sessions already narrowed by branch/date, using the
 * attendance_records session_id indexes. Same 12-week report:
 * ~15-35 ms. Output columns and semantics are byte-identical to the
 * view (verified by a full cross-check of 7 000+ sessions).
 *
 * A subquery WITHOUT GROUP BY always yields exactly one row per
 * session — including headcount sessions that have no records at all
 * (the mode CASE reads the stored tallies) and register sessions with
 * zero records (COUNT over the empty input is 0). This is why it is a
 * LEFT JOIN rather than the view's LEFT JOIN + GROUP BY.
 *
 * Consumers: AttendanceStatsService, AttendanceSummaryService,
 * AttendanceController::sundays, ReportsController::attendanceTrends.
 */
final class AttendanceCounts
{
    private function __construct() {}

    /**
     * The LATERAL subquery SQL, correlated against the outer sessions
     * table under the given alias.
     */
    public static function subquery(string $sessionAlias = 's'): string
    {
        return <<<SQL
            SELECT
                CASE WHEN {$sessionAlias}.attendance_mode = 'headcount'
                     THEN COALESCE({$sessionAlias}.male_count, 0) + COALESCE({$sessionAlias}.female_count, 0)
                     ELSE COUNT(ar.id) FILTER (WHERE ar.is_present AND ar.deleted_at IS NULL AND ar.member_id IS NOT NULL)
                END AS adult_count,
                CASE WHEN {$sessionAlias}.attendance_mode = 'headcount'
                     THEN COALESCE({$sessionAlias}.children_count, 0)
                     ELSE COUNT(ar.id) FILTER (WHERE ar.is_present AND ar.deleted_at IS NULL AND ar.child_id IS NOT NULL)
                END AS children_count,
                CASE WHEN {$sessionAlias}.attendance_mode = 'headcount'
                     THEN COALESCE({$sessionAlias}.male_count, 0) + COALESCE({$sessionAlias}.female_count, 0) + COALESCE({$sessionAlias}.children_count, 0)
                     ELSE COUNT(ar.id) FILTER (WHERE ar.is_present AND ar.deleted_at IS NULL)
                END AS total_count,
                CASE WHEN {$sessionAlias}.attendance_mode = 'headcount'
                     THEN COALESCE({$sessionAlias}.male_count, 0) + COALESCE({$sessionAlias}.female_count, 0) + COALESCE({$sessionAlias}.children_count, 0)
                     ELSE COUNT(ar.id) FILTER (WHERE ar.deleted_at IS NULL)
                END AS records_total,
                CASE WHEN {$sessionAlias}.attendance_mode = 'headcount' THEN COALESCE({$sessionAlias}.male_count, 0) ELSE NULL END AS male_count,
                CASE WHEN {$sessionAlias}.attendance_mode = 'headcount' THEN COALESCE({$sessionAlias}.female_count, 0) ELSE NULL END AS female_count
            FROM attendance_records ar
            WHERE ar.session_id = {$sessionAlias}.id
        SQL;
    }

    /**
     * LEFT JOIN LATERAL the per-session counts onto a query whose FROM
     * already aliases the sessions table as {$sessionAlias}. Exposes the
     * counts under the alias "c" with the same column names as the view,
     * so SELECT lists are unchanged.
     */
    public static function applyLateral(Builder $query, string $sessionAlias = 's'): void
    {
        $query->leftJoinLateral(self::subquery($sessionAlias), 'c');
    }
}
