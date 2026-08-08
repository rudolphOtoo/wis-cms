<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceSession;
use App\Support\AttendanceCounts;
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
     * @param  string  $branchId  Branch to scope data to.
     * @param  list<string>  $cellIds  When non-empty, only sessions for these cells are considered.
     * @param  list<string>  $departmentIds  When non-empty, only sessions for these departments are considered.
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
    public function getStats(string $branchId, array $cellIds = [], array $departmentIds = []): array
    {
        // ─── Q1: Unified aggregation — one query over the counts LATERAL ────
        // The LATERAL join resolves adult/children/total per session for both
        // register and headcount modes while staying index-scoped to the
        // sessions already narrowed by branch/date (the attendance_session_counts
        // view would re-scan every attendance record on every read). Left-joining
        // the service types + cells keeps Sunday-scope filtering and the cell
        // breakdown.
        $sessionAggregates = DB::table('attendance_sessions as s')
            ->leftJoinLateral(AttendanceCounts::subquery('s'), 'c')
            ->join('service_types as st', 's.service_type_id', '=', 'st.id')
            ->leftJoin('cells as cell', 's.cell_id', '=', 'cell.id')
            ->where('s.branch_id', $branchId)
            ->where(function ($q) {
                $q->whereIn('st.type', ['adult', 'children'])
                    ->orWhere(function ($q) {
                        $q->where('st.slug', 'cell_meeting')
                            ->whereRaw('EXTRACT(DOW FROM s.service_date) = 0');
                    });
            })
            ->when($cellIds || $departmentIds, fn ($q) => $q->where(function ($q) use ($cellIds, $departmentIds) {
                if ($cellIds) {
                    $q->whereIn('s.cell_id', $cellIds);
                }
                if ($departmentIds) {
                    $q->orWhereIn('s.department_id', $departmentIds);
                }
            }))
            ->select([
                's.service_date',
                's.cell_id',
                'st.name AS service_type_name',
                'st.type AS service_type',
                DB::raw("COALESCE(cell.name, 'Unassigned') AS cell_name"),
                'c.adult_count',
                'c.children_count',
            ])
            ->orderByDesc('s.service_date')
            ->get();

        // ─── Group by date for O(1) slicing ──────────────────────────────────
        /** @var Collection<string, Collection> $byDate */
        $byDate = $sessionAggregates->groupBy('service_date');
        $allDates = $byDate->keys()->sortDesc()->values();

        // ─── Compute total per row as adult_count + children_count ──────────
        $sessionAggregates = $sessionAggregates->map(fn ($r) => (object) [
            'service_date' => $r->service_date,
            'cell_id' => $r->cell_id,
            'service_type_name' => $r->service_type_name,
            'service_type' => $r->service_type,
            'cell_name' => $r->service_type === 'children'
                ? 'Children Ministry'
                : ($r->cell_name ?: 'Unassigned'),
            'adult_count' => (int) $r->adult_count,
            'children_count' => (int) $r->children_count,
            'count' => (int) $r->adult_count + (int) $r->children_count,
        ]);

        // ─── Group by date for O(1) slicing ──────────────────────────────────
        /** @var Collection<string, Collection> $byDate */
        $byDate = $sessionAggregates->groupBy('service_date');
        $allDates = $byDate->keys()->sortDesc()->values();

        // ─── Last Sunday (most recent service date) ──────────────────────────
        $lastSundayDate = $allDates->first();
        $lastSundayTotal = 0;
        $lastSundayAdults = 0;
        $lastSundayChildren = 0;
        $lastSundayByCell = [];

        if ($lastSundayDate) {
            foreach ($byDate->get($lastSundayDate) as $row) {
                $lastSundayTotal += $row->count;
                $lastSundayAdults += $row->adult_count;
                $lastSundayChildren += $row->children_count;
                $cellName = $row->cell_name;
                $lastSundayByCell[$cellName] = ($lastSundayByCell[$cellName] ?? 0) + $row->count;
            }
        }

        // ─── Q2: Total sessions (lightweight count) ───────────────────────────
        $totalSessions = AttendanceSession::where('branch_id', $branchId)
            ->when($cellIds || $departmentIds, fn ($q) => $q->where(function ($q) use ($cellIds, $departmentIds) {
                if ($cellIds) {
                    $q->whereIn('cell_id', $cellIds);
                }
                if ($departmentIds) {
                    $q->orWhereIn('department_id', $departmentIds);
                }
            }))
            ->count();

        // ─── Average (last 4 distinct service dates) ─────────────────────────
        $last4Totals = $allDates->take(4)->map(fn (string $date) => [
            'date' => $date,
            'total' => (int) $byDate->get($date)->sum('count'),
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
            'count' => (int) $byDate->get($date)->sum('count'),
        ])->values();

        // ─── Monthly trend (last 6 calendar months) ────────────────────────────
        $sixMonthsCutoff = now()->subMonths(6)->startOfMonth();
        $monthlyTrend = $sessionAggregates
            ->filter(fn ($r) => Carbon::parse($r->service_date)->gte($sixMonthsCutoff))
            ->groupBy(fn ($r) => Carbon::parse($r->service_date)->format('Y-m'))
            ->map(fn (Collection $group, string $key) => [
                'month' => Carbon::createFromFormat('Y-m', $key)->format('M'),
                'total' => (int) $group->sum('count'),
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

        // ─── Insights: service type with highest average count ───────────────
        $topService = $sessionAggregates
            ->groupBy('service_type_name')
            ->map(fn (Collection $g) => $g->avg('count'))
            ->sortDesc()
            ->keys()
            ->first();

        // ─── Separate averages for adults and children ───────────────────────
        $avgAdults = $sessionAggregates->count() > 0
            ? (int) round($sessionAggregates->avg('adult_count'))
            : 0;

        $avgChildren = $sessionAggregates->count() > 0
            ? (int) round($sessionAggregates->avg('children_count'))
            : 0;

        return [
            'last_sunday' => [
                'total' => $lastSundayTotal,
                'adults' => $lastSundayAdults,
                'children' => $lastSundayChildren,
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
                'avg_children' => $avgChildren,
                'trend_direction' => $trendDirection,
            ],
        ];
    }
}
