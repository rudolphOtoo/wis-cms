<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reporting endpoints for council monthly review.
 *
 * Each report scopes to the authenticated user's branch and respects
 * the SoftDeletes scope on Transaction (excludes trashed rows).
 *
 * Reports are READ-ONLY aggregations gated by the existing
 * 'view finance' permission (route group enforces).
 */
class ReportsController extends Controller
{
    // GET /api/reports/finance/income-by-category
    //
    // Returns monthly totals per income category for a date range,
    // plus summary aggregates (grand total, category percentages,
    // monthly average).
    //
    // Query params:
    //   from_date (optional, default: 6 months ago)
    //   to_date   (optional, default: today)
    public function incomeByCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])
            : Carbon::today();

        $from = isset($validated['from_date'])
            ? Carbon::parse($validated['from_date'])
            : $to->copy()->subMonthsNoOverflow(6)->startOfMonth();

        $branchId = $request->user()->branch_id;

        // Pass A: month + category breakdown (drives chart and table)
        // Postgres TO_CHAR(date, 'YYYY-MM') groups by month cleanly.
        $rows = Transaction::query()
            ->where('branch_id', $branchId)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$from, $to])
            ->select([
                DB::raw("TO_CHAR(transaction_date, 'YYYY-MM') AS month"),
                'category_id',
                DB::raw('SUM(amount) AS total'),
            ])
            ->groupBy('month', 'category_id')
            ->orderBy('month')
            ->orderBy('category_id')
            ->with('category:id,name')
            ->get()
            ->map(fn ($r) => [
                'month' => $r->month,
                'category_id' => $r->category_id,
                'category_name' => $r->category?->name ?? '(unknown)',
                'total' => (float) $r->total,
            ]);

        // Pass B: category totals (for summary percentages + top)
        $categoryTotals = Transaction::query()
            ->where('branch_id', $branchId)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$from, $to])
            ->select(['category_id', DB::raw('SUM(amount) AS total')])
            ->groupBy('category_id')
            ->with('category:id,name')
            ->get();

        $grandTotal = (float) $categoryTotals->sum('total');

        // Count distinct months in range for monthly_average
        // (use the rows we already have to avoid another query)
        $monthCount = $rows->pluck('month')->unique()->count() ?: 1;

        $categoryBreakdown = $categoryTotals
            ->map(fn ($c) => [
                'category_id' => $c->category_id,
                'category_name' => $c->category?->name ?? '(unknown)',
                'total' => (float) $c->total,
                'percentage' => $grandTotal > 0
                    ? round(((float) $c->total / $grandTotal) * 100, 1)
                    : 0.0,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $topCategory = $categoryBreakdown[0]['category_name'] ?? null;

        return response()->json([
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'rows' => $rows->values(),
            'summary' => [
                'grand_total' => round($grandTotal, 2),
                'monthly_average' => round($grandTotal / $monthCount, 2),
                'month_count' => $monthCount,
                'top_category' => $topCategory,
                'category_totals' => $categoryBreakdown,
            ],
        ]);
    }

    // GET /api/reports/attendance/trends
    //
    // Returns attendance counts and rates per week or month over a
    // date range, with per-service-type breakdown.
    //
    // Query params:
    //   from_date         (optional, default: 12 weeks ago)
    //   to_date           (optional, default: today)
    //   group_by          (optional, 'week' or 'month', default 'week')
    //   service_type_id   (optional, filter to a single service type)
    //
    // DATA-QUALITY NOTES
    //
    //   1. Sessions with zero attendance_records are EXCLUDED from
    //      every metric. The inner join to attendance_records means
    //      a session where no one was marked present-or-absent
    //      simply doesn't appear. This is correct: such sessions
    //      have no information content about attendance rate.
    //
    //   2. Low-sample weeks can produce volatile rates. A week with
    //      a single 7-person cell meeting where everyone showed up
    //      will report 100% even though the result isn't statistically
    //      meaningful. The UI should surface records_total alongside
    //      attendance_rate so council can judge confidence. The
    //      trend.direction comparison uses 4-week aggregates which
    //      smooths over this naturally.
    public function attendanceTrends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'group_by' => 'nullable|in:week,month',
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
        ]);

        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])
            : Carbon::today();
        $from = isset($validated['from_date'])
            ? Carbon::parse($validated['from_date'])
            : $to->copy()->subWeeks(12)->startOfWeek();
        $groupBy = $validated['group_by'] ?? 'week';
        $serviceTypeFilter = $validated['service_type_id'] ?? null;

        $branchId = $request->user()->branch_id;

        // Postgres date_trunc gives us week-start or month-start as a
        // DATE - perfect for bucketing. Pass the unit as a literal,
        // never a user-supplied value (we validated 'week'|'month').
        $bucket = "DATE_TRUNC('{$groupBy}', service_date)::date";

        // Pass A: per-bucket aggregates (drives the chart)
        $base = AttendanceSession::query()
            ->where('attendance_sessions.branch_id', $branchId)
            ->whereBetween('service_date', [$from, $to]);

        if ($serviceTypeFilter) {
            $base->where('service_type_id', $serviceTypeFilter);
        }

        $rows = (clone $base)
            ->join('attendance_records', 'attendance_sessions.id', '=', 'attendance_records.session_id')
            ->join('service_types', 'attendance_sessions.service_type_id', '=', 'service_types.id')
            ->select([
                DB::raw("{$bucket} AS period_start"),
                'service_types.name AS service_type_name',
                DB::raw('COUNT(DISTINCT attendance_sessions.id) AS sessions_count'),
                DB::raw('COUNT(attendance_records.id) AS records_total'),
                DB::raw('SUM(CASE WHEN attendance_records.is_present THEN 1 ELSE 0 END) AS records_present'),
            ])
            ->groupBy('period_start', 'service_types.name')
            ->orderBy('period_start')
            ->orderBy('service_types.name')
            ->get();

        // Reshape: one row per period, service types as sub-map.
        $byPeriod = [];
        foreach ($rows as $r) {
            $key = $r->period_start;
            if (! isset($byPeriod[$key])) {
                $byPeriod[$key] = [
                    'period_start' => $r->period_start,
                    'period_label' => $this->formatPeriodLabel($r->period_start, $groupBy),
                    'sessions_count' => 0,
                    'records_total' => 0,
                    'records_present' => 0,
                    'records_absent' => 0,
                    'attendance_rate' => 0.0,
                    'by_service_type' => [],
                ];
            }
            $present = (int) $r->records_present;
            $total = (int) $r->records_total;
            $byPeriod[$key]['sessions_count'] += (int) $r->sessions_count;
            $byPeriod[$key]['records_total'] += $total;
            $byPeriod[$key]['records_present'] += $present;
            $byPeriod[$key]['records_absent'] += ($total - $present);
            $byPeriod[$key]['by_service_type'][$r->service_type_name] = [
                'present' => $present,
                'total' => $total,
            ];
        }

        // Per-period attendance_rate
        foreach ($byPeriod as &$row) {
            $row['attendance_rate'] = $row['records_total'] > 0
                ? round(($row['records_present'] / $row['records_total']) * 100, 1)
                : 0.0;
        }
        unset($row);

        $periodRows = array_values($byPeriod);

        // Pass B: overall summary across the whole range
        $summary = (clone $base)
            ->join('attendance_records', 'attendance_sessions.id', '=', 'attendance_records.session_id')
            ->select([
                DB::raw('COUNT(DISTINCT attendance_sessions.id) AS total_sessions'),
                DB::raw('COUNT(attendance_records.id) AS records_total'),
                DB::raw('SUM(CASE WHEN attendance_records.is_present THEN 1 ELSE 0 END) AS records_present'),
            ])
            ->first();

        $totalSessions = (int) ($summary->total_sessions ?? 0);
        $recordsTotal = (int) ($summary->records_total ?? 0);
        $recordsPresent = (int) ($summary->records_present ?? 0);
        $recordsAbsent = $recordsTotal - $recordsPresent;
        $overallRate = $recordsTotal > 0
            ? round(($recordsPresent / $recordsTotal) * 100, 1)
            : 0.0;
        $avgPerSession = $totalSessions > 0
            ? round($recordsPresent / $totalSessions, 1)
            : 0.0;

        return response()->json([
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'group_by' => $groupBy,
            ],
            'rows' => $periodRows,
            'summary' => [
                'total_sessions' => $totalSessions,
                'total_present' => $recordsPresent,
                'total_absent' => $recordsAbsent,
                'overall_attendance_rate' => $overallRate,
                'avg_per_session' => $avgPerSession,
                'trend' => $this->computeAttendanceTrend($periodRows),
            ],
        ]);
    }

    /**
     * Human-readable label for a bucket start date.
     *   'week'  -> 'Mar 9-15'  (week starting Mar 9)
     *   'month' -> 'March 2026'
     */
    protected function formatPeriodLabel(string $periodStart, string $groupBy): string
    {
        $start = Carbon::parse($periodStart);
        if ($groupBy === 'month') {
            return $start->format('F Y');
        }
        $end = $start->copy()->addDays(6);

        // When start and end fall in the same month, show 'Mar 9-15'.
        // When the week crosses a month boundary, show 'Mar 30-Apr 5'.
        if ($start->month === $end->month) {
            return $start->format('M j').'-'.$end->format('j');
        }

        return $start->format('M j').'-'.$end->format('M j');
    }

    /**
     * Trend direction: last 4 periods' attendance rate vs prior 4.
     * Threshold of +/- 3 percentage points to count as a real change
     * (smaller swings are noise at WIS's data scale).
     */
    protected function computeAttendanceTrend(array $rows): array
    {
        $recent = array_slice($rows, -4);
        $prior = array_slice($rows, -8, 4);

        $rateOf = function (array $sample): float {
            $present = array_sum(array_column($sample, 'records_present'));
            $total = array_sum(array_column($sample, 'records_total'));

            return $total > 0 ? round(($present / $total) * 100, 1) : 0.0;
        };

        $recentRate = $rateOf($recent);
        $priorRate = $rateOf($prior);
        $delta = round($recentRate - $priorRate, 1);

        if (count($prior) === 0) {
            $direction = 'unknown';
        } elseif ($delta >= 3) {
            $direction = 'up';
        } elseif ($delta <= -3) {
            $direction = 'down';
        } else {
            $direction = 'flat';
        }

        return [
            'direction' => $direction,
            'recent_rate' => $recentRate,
            'prior_rate' => $priorRate,
            'delta' => $delta,
        ];
    }
}
