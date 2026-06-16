<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PERF-06 FIX: Encapsulates all attendance statistics aggregations.
 *
 * PROBLEM (original AttendanceController::stats):
 *   - Looped over 4 "recent Sunday" dates, executing 1 query per date to
 *     load sessions, then called $session->adult_count per session — which
 *     fired an additional COUNT query each time (PERF-01).
 *   - Looped over 8 "chart" dates with the same pattern.
 *   - Separately loaded ALL adult sessions for insights (another query).
 *   Total: O(N_dates × N_sessions_per_date) queries, typically 30–80+.
 *
 * SOLUTION:
 *   Single aggregated LEFT JOIN query that returns per-session adult counts
 *   for all time. All slicing (last Sunday, chart, average, monthly trend,
 *   insights) is done in PHP on the in-memory collection.
 *   Total: 2 queries (aggregated session counts + total session counter).
 *
 * NOTE: Raw DB::table() is used here, not the Eloquent model, because we
 * need an explicit JOIN to attendance_records. The BelongsToBranch global
 * scope does NOT apply to raw queries — the branch_id filter is explicitly
 * included in the JOIN condition to close that gap.
 */
class AttendanceStatsService
{
    /**
     * Build all attendance statistics for a given branch.
     *
     * @return array{
     *   last_sunday: array{total: int, by_cell: array<string, int>, date: string|null},
     *   average: int,
     *   total_sessions: int,
     *   chart: list<array{date: string, count: int}>,
     *   monthly_trend: list<array{month: string, total: int}>,
     *   week_over_week_pct: float|null,
     *   insights: array{top_service: string, avg_adults: int, avg_children: int, trend_direction: string},
     * }
     */
    public function getStats(string $branchId): array
    {
        // ─── Q1: Aggregated adult counts for all adult sessions ────────────────
        // LEFT JOIN so sessions with zero marked attendance still appear.
        // We filter is_present AND member_id IS NOT NULL in SQL to keep the
        // result set small. deleted_at filter respects the SoftDeletes added
        // in migration PERF-07.
        $sessionAggregates = DB::table('attendance_sessions as s')
            ->join('service_types as st', 's.service_type_id', '=', 'st.id')
            ->leftJoin('attendance_records as ar', function ($join) {
                $join->on('ar.session_id', '=', 's.id')
                    ->whereNull('ar.deleted_at')
                    ->whereNotNull('ar.member_id')
                    ->where('ar.is_present', '=', true);
            })
            ->leftJoin('cells as c', 's.cell_id', '=', 'c.id')
            ->where('s.branch_id', $branchId)      // explicit: BranchScope doesn't apply here
            ->where('st.type', 'adult')
            ->select([
                's.service_date',
                's.cell_id',
                'st.name AS service_type_name',
                DB::raw("COALESCE(c.name, 'Unassigned') AS cell_name"),
                DB::raw('COUNT(ar.id) AS adult_count'),
            ])
            ->groupBy('s.service_date', 's.cell_id', 'st.name', 'c.name')
            ->orderByDesc('s.service_date')
            ->get();

        // ─── Group by date for O(1) slicing ──────────────────────────────────
        /** @var Collection<string, Collection> $byDate */
        $byDate = $sessionAggregates->groupBy('service_date');
        $allDates = $byDate->keys()->sortDesc()->values();

        // ─── Last Sunday (most recent adult service date) ─────────────────────
        $lastSundayDate = $allDates->first();
        $lastSundayTotal = 0;
        $lastSundayByCell = [];

        if ($lastSundayDate) {
            foreach ($byDate->get($lastSundayDate) as $row) {
                $count = (int) $row->adult_count;
                $lastSundayTotal += $count;
                $cellName = $row->cell_name;
                $lastSundayByCell[$cellName] = ($lastSundayByCell[$cellName] ?? 0) + $count;
            }
        }

        // ─── Q2: Total sessions (lightweight count) ───────────────────────────
        $totalSessions = AttendanceSession::where('branch_id', $branchId)->count();

        // ─── Average (last 4 distinct adult-service dates) ───────────────────
        $last4Totals = $allDates->take(4)->map(fn (string $date) => [
            'date' => $date,
            'total' => (int) $byDate->get($date)->sum('adult_count'),
        ]);

        $avgAttendance = $last4Totals->count() > 0
            ? (int) round($last4Totals->avg('total'))
            : 0;

        // ─── Week-over-week percentage change ─────────────────────────────────
        $weekOverWeek = null;
        if ($last4Totals->count() >= 2) {
            $current = $last4Totals->first()['total'];
            $previous = $last4Totals->skip(1)->first()['total'];
            if ($previous > 0) {
                $weekOverWeek = round((($current - $previous) / $previous) * 100, 1);
            }
        }

        // ─── Chart: last 8 dates, oldest-first for UI left→right display ─────
        $chartDates = $allDates->take(8)->reverse()->values();
        $chartData = $chartDates->map(fn (string $date) => [
            'date' => Carbon::parse($date)->format('d M'),
            'count' => (int) $byDate->get($date)->sum('adult_count'),
        ])->values();

        // ─── Monthly trend (last 6 calendar months) ────────────────────────────
        $sixMonthsCutoff = now()->subMonths(6)->startOfMonth();
        $monthlyTrend = $sessionAggregates
            ->filter(fn ($r) => Carbon::parse($r->service_date)->gte($sixMonthsCutoff))
            ->groupBy(fn ($r) => Carbon::parse($r->service_date)->format('Y-m'))
            ->map(fn (Collection $group, string $key) => [
                'month' => Carbon::createFromFormat('Y-m', $key)->format('M'),
                'total' => (int) $group->sum('adult_count'),
            ])
            ->sortKeys()
            ->values();

        // ─── Trend direction (most recent month vs. prior month) ──────────────
        $trendDirection = 'flat';
        if ($monthlyTrend->count() >= 2) {
            $last = $monthlyTrend->last()['total'];
            $prev = $monthlyTrend[$monthlyTrend->count() - 2]['total'];
            $trendDirection = match (true) {
                $last > $prev => 'up',
                $last < $prev => 'down',
                default => 'flat',
            };
        }

        // ─── Insights: service type with highest average adult count ──────────
        $topService = $sessionAggregates
            ->groupBy('service_type_name')
            ->map(fn (Collection $g) => $g->avg('adult_count'))
            ->sortDesc()
            ->keys()
            ->first();

        $avgAdults = $sessionAggregates->count() > 0
            ? (int) round($sessionAggregates->avg('adult_count'))
            : 0;

        return [
            'last_sunday' => [
                'total' => $lastSundayTotal,
                'by_cell' => $lastSundayByCell,
                'date' => $lastSundayDate,
            ],
            'average' => $avgAttendance,
            'total_sessions' => $totalSessions,
            'chart' => $chartData,
            'monthly_trend' => $monthlyTrend,
            'week_over_week_pct' => $weekOverWeek,
            'insights' => [
                'top_service' => $topService ?? 'N/A',
                'avg_adults' => $avgAdults,
                'avg_children' => 0,
                'trend_direction' => $trendDirection,
            ],
        ];
    }
}
