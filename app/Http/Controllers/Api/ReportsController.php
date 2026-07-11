<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\Branch;
use App\Models\Cell;
use App\Models\Transaction;
use App\Services\AttendanceSummaryService;
use App\Services\MemberWelfareService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function __construct(
        private readonly AttendanceSummaryService $summaryService,
        private readonly MemberWelfareService $welfareService,
    ) {}

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
        return response()->json($this->buildIncomeData($request));
    }

    /**
     * Build the income-by-category dataset. Used by JSON, PDF, and CSV
     * export endpoints.
     */
    protected function buildIncomeData(Request $request): array
    {
        return $this->buildTransactionCategoryData($request, 'income');
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
        return response()->json($this->buildAttendanceTrendsData($request));
    }

    /**
     * Build the attendance-trends dataset. Used by JSON, PDF, and CSV
     * export endpoints.
     */
    protected function buildAttendanceTrendsData(Request $request): array
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

        // Postgres date_trunc gives us week-start or month-start as a
        // DATE - perfect for bucketing. Pass the unit as a literal,
        // never a user-supplied value (we validated 'week'|'month').
        $bucket = "DATE_TRUNC('{$groupBy}', service_date)::date";

        // Pass A: per-bucket aggregates (drives the chart)
        // Branch scoping handled by BelongsToBranch trait on AttendanceSession.
        $base = AttendanceSession::query()
            ->whereBetween('service_date', [$from, $to]);

        if ($serviceTypeFilter) {
            $base->where('service_type_id', $serviceTypeFilter);
        }

        // Sunday cell meetings count toward Sunday Adult Service.
        // Leadership rule: cell leaders take attendance during the
        // service breakout; that cell register IS the Sunday service
        // attendance for those members. Direct sunday_adult sessions
        // ALSO count (dual-mode: legacy data + new flow both included).
        // DOW: Postgres EXTRACT day-of-week, 0=Sunday.
        $effectiveType = "CASE
            WHEN service_types.slug = 'cell_meeting'
                 AND EXTRACT(DOW FROM service_date) = 0
            THEN 'Sunday Adult Service'
            ELSE service_types.name
        END";

        $rows = (clone $base)
            ->join('attendance_records', 'attendance_sessions.id', '=', 'attendance_records.session_id')
            ->join('service_types', 'attendance_sessions.service_type_id', '=', 'service_types.id')
            ->select([
                DB::raw("{$bucket} AS period_start"),
                DB::raw("{$effectiveType} AS service_type_name"),
                DB::raw('COUNT(DISTINCT attendance_sessions.id) AS sessions_count'),
                DB::raw('COUNT(attendance_records.id) AS records_total'),
                DB::raw('SUM(CASE WHEN attendance_records.is_present THEN 1 ELSE 0 END) AS records_present'),
            ])
            ->groupBy(DB::raw('period_start'), DB::raw($effectiveType))
            ->orderBy('period_start')
            ->orderBy(DB::raw($effectiveType))
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

        // Derive overall summary from the period data (avoids a second scan)
        $totalSessions = collect($periodRows)->sum('sessions_count');
        $recordsTotal = collect($periodRows)->sum('records_total');
        $recordsPresent = collect($periodRows)->sum('records_present');
        $recordsAbsent = $recordsTotal - $recordsPresent;
        $overallRate = $recordsTotal > 0
            ? round(($recordsPresent / $recordsTotal) * 100, 1)
            : 0.0;
        $avgPerSession = $totalSessions > 0
            ? round($recordsPresent / $totalSessions, 1)
            : 0.0;

        return [
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
        ];
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

    // GET /api/reports/finance/expense-by-category
    //
    // Monthly totals per expense category for a date range, plus
    // summary aggregates. Structurally identical to incomeByCategory;
    // only difference is the type='expense' filter.
    //
    // Query params:
    //   from_date (optional, default: 6 months ago)
    //   to_date   (optional, default: today)
    public function expenseByCategory(Request $request): JsonResponse
    {
        return response()->json($this->buildExpenseData($request));
    }

    /**
     * Build the expense-by-category dataset. Used by JSON, PDF, and CSV
     * export endpoints.
     */
    protected function buildExpenseData(Request $request): array
    {
        return $this->buildTransactionCategoryData($request, 'expense');
    }

    /**
     * SMELL-02 FIX: Single builder for income AND expense category reports.
     *
     * The old code had two ~70-line methods (`buildIncomeData` and
     * `buildExpenseData`) that were structurally identical — only the
     * `type` filter differed. All six callers (JSON, PDF, CSV × 2)
     * now route through this single method via the thin wrappers above.
     *
     * @param  string  $type  'income' | 'expense'
     */
    private function buildTransactionCategoryData(Request $request, string $type): array
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

        // Pass A: month + category breakdown (drives chart and table).
        // TO_CHAR(date, 'YYYY-MM') groups by month cleanly in Postgres.
        // BelongsToBranch trait applies the branch_id global scope.
        $rows = Transaction::query()
            ->where('type', $type)
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
                'month' => Carbon::parse($r->month.'-01')->format('M Y'),
                'category_id' => $r->category_id,
                'category_name' => $r->category?->name ?? '(unknown)',
                'total' => (float) $r->total,
            ]);

        // Pass B: category totals for summary percentages and top-category.
        $categoryTotals = Transaction::query()
            ->where('type', $type)
            ->whereBetween('transaction_date', [$from, $to])
            ->select(['category_id', DB::raw('SUM(amount) AS total')])
            ->groupBy('category_id')
            ->with('category:id,name')
            ->get();

        $grandTotal = (float) $categoryTotals->sum('total');
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

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'rows' => $rows->values()->all(),
            'summary' => [
                'grand_total' => round($grandTotal, 2),
                'monthly_average' => round($grandTotal / $monthCount, 2),
                'month_count' => $monthCount,
                'top_category' => $categoryBreakdown[0]['category_name'] ?? null,
                'category_totals' => $categoryBreakdown,
            ],
        ];
    }

    // GET /api/reports/cells/comparison
    //
    // Cell health snapshot for the authenticated user's branch.
    // Returns every cell with its leader, member count, and
    // recent-window attendance signals. Designed for council
    // to see at a glance which cells have leaders, which are
    // recording attendance, and where intervention is needed.
    //
    // Framed as 'comparison' but emphasises STRUCTURAL HEALTH
    // (do cells have leaders? are they recording attendance?)
    // rather than performance, because current data doesn't
    // support fair performance comparison (only one cell has
    // recent attendance records).
    //
    // Query params:
    //   weeks (optional, default 4) - window for 'recent' signals
    public function cellComparison(Request $request): JsonResponse
    {
        return response()->json($this->buildCellComparisonData($request));
    }

    /**
     * Build the cell-comparison dataset. Used by JSON, PDF, and CSV
     * export endpoints.
     */
    protected function buildCellComparisonData(Request $request): array
    {
        $validated = $request->validate([
            'weeks' => 'nullable|integer|min:1|max:52',
        ]);

        $weeks = $validated['weeks'] ?? 4;
        $cutoff = Carbon::today()->subWeeks($weeks);

        // Branch id is required for the DB::table() raw query below
        // (Pass B). BelongsToBranch trait global scope doesn't apply
        // to raw query builder, so the filter must be explicit.
        $branchId = $request->user()->branch_id;

        // Pass A: cells + leader + active member count
        // Use a subquery for member_count rather than a join with
        // GROUP BY (cleaner with the leader join already in place).
        // Branch scoping handled by BelongsToBranch trait on Cell.
        $cells = Cell::query()
            ->with(['leader:id,name'])
            ->withCount(['members' => function ($q) {
                $q->whereNull('deleted_at')->where('status', 'active');
            }])
            ->orderBy('name')
            ->get();

        // Pass B: attendance signals within the recency window,
        // keyed by cell_id for cheap lookup.
        // NOTE: This uses DB::table() (raw query builder), so the
        // BelongsToBranch global scope on AttendanceSession does NOT
        // apply. The explicit branch_id filter MUST stay - removing it
        // would leak attendance data across branches.
        $attendanceByCell = DB::table('attendance_sessions as s')
            ->leftJoin('attendance_records as r', 'r.session_id', '=', 's.id')
            ->where('s.branch_id', $branchId)
            ->whereNotNull('s.cell_id')
            ->where('s.service_date', '>=', $cutoff)
            ->select(
                's.cell_id',
                DB::raw('COUNT(DISTINCT s.id) as sessions'),
                DB::raw('COUNT(r.id) as records_total'),
                DB::raw('SUM(CASE WHEN r.is_present THEN 1 ELSE 0 END) as records_present'),
                DB::raw('MAX(s.service_date) as last_session_date')
            )
            ->groupBy('s.cell_id')
            ->get()
            ->keyBy('cell_id');

        // Pass C: welfare distribution per cell (from members.welfare_flag)
        $welfareByCell = DB::table('members as m')
            ->where('m.branch_id', $branchId)
            ->whereNull('m.deleted_at')
            ->where('m.status', 'active')
            ->select(
                'm.cell_id',
                DB::raw("SUM(CASE WHEN m.welfare_flag = 'engaged' THEN 1 ELSE 0 END) AS engaged"),
                DB::raw("SUM(CASE WHEN m.welfare_flag = 'moderate' THEN 1 ELSE 0 END) AS moderate"),
                DB::raw("SUM(CASE WHEN m.welfare_flag = 'at_risk' THEN 1 ELSE 0 END) AS at_risk"),
                DB::raw("SUM(CASE WHEN m.welfare_flag = 'inactive_risk' THEN 1 ELSE 0 END) AS inactive_risk")
            )
            ->groupBy('m.cell_id')
            ->get()
            ->keyBy('cell_id');

        // Shape per-cell rows with health flags
        $cellRows = $cells->map(function ($cell) use ($attendanceByCell, $welfareByCell) {
            $att = $attendanceByCell->get($cell->id);
            $sessions = (int) ($att->sessions ?? 0);
            $recordsTotal = (int) ($att->records_total ?? 0);
            $recordsPresent = (int) ($att->records_present ?? 0);
            $rate = $recordsTotal > 0
                ? round(($recordsPresent / $recordsTotal) * 100, 1)
                : null;

            $welfare = $welfareByCell->get($cell->id);
            $welfareDistribution = $welfare ? [
                'engaged' => (int) $welfare->engaged,
                'moderate' => (int) $welfare->moderate,
                'at_risk' => (int) $welfare->at_risk,
                'inactive_risk' => (int) $welfare->inactive_risk,
            ] : ['engaged' => 0, 'moderate' => 0, 'at_risk' => 0, 'inactive_risk' => 0];

            $flags = [];
            if (! $cell->leader_user_id) {
                $flags[] = 'no_leader';
            }
            if ($sessions === 0) {
                $flags[] = 'no_recent_attendance';
            }
            if ($cell->members_count < 5) {
                $flags[] = 'low_membership';
            }
            // New: high inactive rate (>20% of members are inactive_risk)
            $totalForInactive = $welfareDistribution['engaged'] + $welfareDistribution['moderate']
                + $welfareDistribution['at_risk'] + $welfareDistribution['inactive_risk'];
            if ($totalForInactive > 0 && $welfareDistribution['inactive_risk'] / $totalForInactive > 0.2) {
                $flags[] = 'high_inactive_rate';
            }

            return [
                'id' => $cell->id,
                'name' => $cell->name,
                'description' => $cell->description,
                'is_active' => (bool) $cell->is_active,
                'leader' => $cell->leader ? [
                    'id' => $cell->leader->id,
                    'name' => $cell->leader->name,
                ] : null,
                'member_count' => (int) $cell->members_count,
                'recent_sessions' => $sessions,
                'recent_records_total' => $recordsTotal,
                'recent_records_present' => $recordsPresent,
                'recent_attendance_rate' => $rate,
                'last_session_date' => $att->last_session_date ?? null,
                'welfare_distribution' => $welfareDistribution,
                'health_flags' => $flags,
            ];
        })->all();

        // Summary stats
        $totalMembers = array_sum(array_column($cellRows, 'member_count'));
        $cellsWithLeader = count(array_filter($cellRows, fn ($c) => $c['leader'] !== null));
        $cellsWithRecentAttendance = count(array_filter($cellRows, fn ($c) => $c['recent_sessions'] > 0));

        // Average attendance rate across cells THAT HAVE data
        // (avoid skewing 0% for cells with no recorded sessions)
        $ratesAvailable = array_filter(
            array_column($cellRows, 'recent_attendance_rate'),
            fn ($r) => $r !== null
        );
        $avgRate = count($ratesAvailable) > 0
            ? round(array_sum($ratesAvailable) / count($ratesAvailable), 1)
            : null;

        return [
            'period' => [
                'from' => $cutoff->toDateString(),
                'to' => Carbon::today()->toDateString(),
                'weeks' => $weeks,
            ],
            'cells' => $cellRows,
            'summary' => [
                'total_cells' => count($cellRows),
                'cells_with_leader' => $cellsWithLeader,
                'cells_with_recent_attendance' => $cellsWithRecentAttendance,
                'total_members' => $totalMembers,
                'avg_members_per_cell' => count($cellRows) > 0
                    ? round($totalMembers / count($cellRows), 1)
                    : 0,
                'avg_attendance_rate' => $avgRate,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // CHURCH ATTENDANCE SUMMARY — cell-to-church aggregation
    // ──────────────────────────────────────────────────────────────────

    // GET /api/reports/attendance/summary
    //
    // Aggregates all cell-level attendance into church-wide totals per
    // Sunday. Cell meetings on Sundays are included (leadership rule:
    // cell attendance during Sunday breakout IS the Sunday attendance).
    //
    // Query params:
    //   from_date (optional, default: 12 weeks ago)
    //   to_date   (optional, default: today)
    //   cell_id   (optional, filter to a single cell)
    public function attendanceSummary(Request $request): JsonResponse
    {
        return response()->json($this->buildAttendanceSummaryData($request));
    }

    /**
     * Build the attendance-summary dataset. Used by JSON, PDF, and CSV
     * export endpoints.
     */
    protected function buildAttendanceSummaryData(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'cell_id' => 'nullable|uuid|exists:cells,id',
        ]);

        $branchId = $request->user()->branch_id;

        return $this->summaryService->getSummary(
            $branchId,
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null,
            $validated['cell_id'] ?? null,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // EXPORT ENDPOINTS — PDF + CSV downloads for each report
    // ──────────────────────────────────────────────────────────────────

    // GET /api/reports/finance/income-by-category/export-pdf
    public function incomeByCategoryPdf(Request $request)
    {
        return $this->financeCategoryPdf($request, 'income');
    }

    // GET /api/reports/finance/income-by-category/export-csv
    public function incomeByCategoryCsv(Request $request)
    {
        return $this->streamCsv($request, 'income');
    }

    // GET /api/reports/finance/expense-by-category/export-pdf
    public function expenseByCategoryPdf(Request $request)
    {
        return $this->financeCategoryPdf($request, 'expense');
    }

    // GET /api/reports/finance/expense-by-category/export-csv
    public function expenseByCategoryCsv(Request $request)
    {
        return $this->streamCsv($request, 'expense');
    }

    private function financeCategoryPdf(Request $request, string $type)
    {
        $data = $this->buildTransactionCategoryData($request, $type);
        $branch = Branch::find($request->user()->branch_id);
        $branchName = $branch?->name ?? 'Wesleyan International Society';

        $logoPath = null;
        $logoFile = public_path('images/wis-logo.png');
        if (file_exists($logoFile)) {
            $logoPath = 'data:image/png;base64,'.base64_encode(file_get_contents($logoFile));
        }

        $pdf = Pdf::loadView('pdf.report-finance-by-category', [
            'data' => $data,
            'branchName' => $branchName,
            'reportType' => $type,
            'accentColor' => $type === 'income' ? '#15803d' : '#ba1a1a',
            'logoPath' => $logoPath,
            'generatedAt' => now()->format('F j, Y \\a\\t g:i a'),
        ]);

        $from = $data['period']['from'];
        $to = $data['period']['to'];

        return $pdf->download("{$type}-financial-report-{$from}-to-{$to}.pdf");
    }

    private function streamCsv(Request $request, string $type): StreamedResponse
    {
        $data = $this->buildTransactionCategoryData($request, $type);
        $from = $data['period']['from'];
        $to = $data['period']['to'];
        $branch = Branch::find($request->user()->branch_id);
        $branchName = $branch?->name ?? 'Wesleyan International Society';
        $label = $type === 'income' ? 'Income' : 'Expense';
        $subtotalLabel = $type === 'income' ? 'Total Income' : 'Total Expenses';

        $filename = "{$type}-financial-report-{$from}-to-{$to}.xlsx";

        // Group rows by month for the matrix
        $rowsByMonth = collect($data['rows'])->groupBy('month')->sortKeys();

        // All unique category names (sorted) for matrix columns
        $categories = collect($data['rows'])
            ->pluck('category_name')
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Per-month totals
        $monthlyTotals = $rowsByMonth->map(
            fn ($rows) => collect($rows)->sum('total'),
        )->all();

        // Styles
        $titleStyle = new Style(fontBold: true, fontSize: 16);
        $sectionStyle = new Style(fontBold: true, fontSize: 13);
        $headerStyle = new Style(fontBold: true, fontSize: 11, backgroundColor: 'FFD9E1F2');
        $amountStyle = new Style(format: '"GHS "#,##0.00');
        $boldStyle = new Style(fontBold: true);
        $boldAmountStyle = new Style(fontBold: true, format: '"GHS "#,##0.00');

        return new StreamedResponse(function () use (
            $data, $branchName, $label, $subtotalLabel,
            $categories, $rowsByMonth, $monthlyTotals,
            $titleStyle, $sectionStyle, $headerStyle,
            $amountStyle, $boldStyle, $boldAmountStyle,
        ) {
            $options = new XlsxOptions(DEFAULT_COLUMN_WIDTH: 18);
            $writer = new Writer($options);
            $writer->openToFile('php://output');

            // ── SECTION 1: REPORT HEADER ──────────────────────────────
            $writer->addRow(Row::fromValuesWithStyle([$branchName], $titleStyle));
            $writer->addRow(Row::fromValuesWithStyle(["{$label} Financial Report"], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues([
                'Period:',
                Carbon::parse($data['period']['from'])->format('F j, Y')
                    .' to '
                    .Carbon::parse($data['period']['to'])->format('F j, Y'),
            ]));
            $writer->addRow(Row::fromValues(['Report Date:', now()->format('F j, Y')]));
            $writer->addRow(Row::fromValues(['']));

            // ── SECTION 2: EXECUTIVE SUMMARY ──────────────────────────
            $writer->addRow(Row::fromValuesWithStyle(['EXECUTIVE SUMMARY'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['Period Covered',
                Carbon::parse($data['period']['from'])->format('M j, Y')
                    .' - '
                    .Carbon::parse($data['period']['to'])->format('M j, Y'),
            ]));
            $writer->addRow(Row::fromValues(['Months Covered', $data['summary']['month_count']]));
            $writer->addRow(Row::fromValuesWithStyle(['Grand Total', $data['summary']['grand_total']], $boldAmountStyle));
            $writer->addRow(Row::fromValuesWithStyle(['Monthly Average', $data['summary']['monthly_average']], $boldAmountStyle));
            if ($data['summary']['top_category']) {
                $writer->addRow(Row::fromValues(['Highest Category', $data['summary']['top_category']]));
            }
            $writer->addRow(Row::fromValues(['']));

            // ── SECTION 3: MONTHLY BREAKDOWN MATRIX ───────────────────
            $writer->addRow(Row::fromValuesWithStyle(['MONTHLY BREAKDOWN'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));

            $headerRow = array_merge(['Month'], $categories, ['Total']);
            $writer->addRow(Row::fromValuesWithStyle($headerRow, $headerStyle));

            foreach ($rowsByMonth as $month => $monthRows) {
                $byCat = collect($monthRows)->keyBy('category_name');
                $cells = [$month];
                foreach ($categories as $cat) {
                    $cells[] = (float) ($byCat[$cat]['total'] ?? 0);
                }
                $cells[] = (float) ($monthlyTotals[$month] ?? 0);
                $writer->addRow(Row::fromValuesWithStyle($cells, $amountStyle));
            }

            // Subtotal row
            $subCells = [$subtotalLabel];
            foreach ($categories as $cat) {
                $subCells[] = (float) (collect($data['summary']['category_totals'])
                    ->firstWhere('category_name', $cat)['total'] ?? 0);
            }
            $subCells[] = (float) $data['summary']['grand_total'];
            $writer->addRow(Row::fromValuesWithStyle($subCells, $boldAmountStyle));
            $writer->addRow(Row::fromValues(['']));

            // ── SECTION 4: CATEGORY TOTALS ────────────────────────────
            $writer->addRow(Row::fromValuesWithStyle(['CATEGORY TOTALS'], $sectionStyle));
            $writer->addRow(Row::fromValuesWithStyle(['Category', 'Total (GHS)', 'Share (%)'], $headerStyle));
            foreach ($data['summary']['category_totals'] as $cat) {
                $writer->addRow(Row::fromValuesWithStyle([
                    $cat['category_name'],
                    $cat['total'],
                    $cat['percentage'] / 100,
                ], $amountStyle));
            }
            $writer->addRow(Row::fromValuesWithStyle([
                'TOTAL',
                $data['summary']['grand_total'],
                1.0,
            ], $boldAmountStyle));
            $writer->addRow(Row::fromValues(['']));

            // ── SECTION 5: MONTHLY TOTALS ─────────────────────────────
            $writer->addRow(Row::fromValuesWithStyle(['MONTHLY TOTALS'], $sectionStyle));
            $writer->addRow(Row::fromValuesWithStyle(['Month', 'Total (GHS)'], $headerStyle));
            foreach ($monthlyTotals as $month => $total) {
                $writer->addRow(Row::fromValuesWithStyle([$month, $total], $amountStyle));
            }
            $writer->addRow(Row::fromValuesWithStyle([
                'TOTAL', $data['summary']['grand_total'],
            ], $boldAmountStyle));
            $writer->addRow(Row::fromValues(['']));

            // ── SECTION 6: NOTES ──────────────────────────────────────
            $writer->addRow(Row::fromValuesWithStyle(['NOTES'], $boldStyle));
            $writer->addRow(Row::fromValues(['1. All amounts are in Ghana Cedis (GHS).']));
            $writer->addRow(Row::fromValues(['2. This report was generated by WIS-CMS on '
                .now()->format('F j, Y \a\t g:i a').'.']));

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // GET /api/reports/attendance/trends/export-pdf
    public function attendanceTrendsPdf(Request $request)
    {
        $data = $this->buildAttendanceTrendsData($request);
        $branch = Branch::find($request->user()->branch_id);

        $pdf = Pdf::loadView('pdf.report-attendance-trends', [
            'data' => $data,
            'branchName' => $branch?->name ?? 'Wesleyan International Society',
            'generatedAt' => now()->format('F j, Y \\a\\t g:i a'),
        ]);

        $from = $data['period']['from'];
        $to = $data['period']['to'];

        return $pdf->download("attendance-trends-{$from}-to-{$to}.pdf");
    }

    // GET /api/reports/attendance/trends/export-csv
    public function attendanceTrendsCsv(Request $request)
    {
        $data = $this->buildAttendanceTrendsData($request);
        $from = $data['period']['from'];
        $to = $data['period']['to'];

        $filename = "attendance-trends-{$from}-to-{$to}.csv";

        return new StreamedResponse(function () use ($data) {
            $writer = new Writer;
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues([
                'Period', 'Sessions', 'Present', 'Absent', 'Total Records', 'Attendance Rate (%)',
            ]));
            foreach ($data['rows'] as $row) {
                $writer->addRow(Row::fromValues([
                    $row['label'] ?? $row['period_start'] ?? '',
                    (string) ($row['sessions'] ?? ''),
                    (string) ($row['records_present'] ?? ''),
                    (string) ($row['records_absent'] ?? ''),
                    (string) ($row['records_total'] ?? ''),
                    (string) ($row['attendance_rate'] ?? ''),
                ]));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['Summary']));
            $writer->addRow(Row::fromValues(['Total Sessions', (string) $data['summary']['total_sessions']]));
            $writer->addRow(Row::fromValues(['Total Present', (string) $data['summary']['total_present']]));
            $writer->addRow(Row::fromValues(['Total Absent', (string) $data['summary']['total_absent']]));
            $writer->addRow(Row::fromValues(['Overall Rate (%)', (string) $data['summary']['overall_attendance_rate']]));
            $writer->addRow(Row::fromValues(['Avg per Session', (string) $data['summary']['avg_per_session']]));

            $writer->close();
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // GET /api/reports/cells/comparison/export-pdf
    public function cellComparisonPdf(Request $request)
    {
        $data = $this->buildCellComparisonData($request);
        $branch = Branch::find($request->user()->branch_id);

        $pdf = Pdf::loadView('pdf.report-cell-comparison', [
            'data' => $data,
            'branchName' => $branch?->name ?? 'Wesleyan International Society',
            'generatedAt' => now()->format('F j, Y \\a\\t g:i a'),
        ]);

        $today = now()->format('Y-m-d');

        return $pdf->download("cell-comparison-{$today}.pdf");
    }

    // GET /api/reports/cells/comparison/export-csv
    public function cellComparisonCsv(Request $request)
    {
        $data = $this->buildCellComparisonData($request);
        $today = now()->format('Y-m-d');
        $filename = "cell-comparison-{$today}.csv";

        return new StreamedResponse(function () use ($data) {
            $writer = new Writer;
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues([
                'Cell', 'Leader', 'Members', 'Recent Sessions', 'Attendance Rate (%)',
                'Last Session', 'Health Flags',
            ]));
            foreach ($data['cells'] as $cell) {
                $writer->addRow(Row::fromValues([
                    $cell['name'],
                    $cell['leader']['name'] ?? '(no leader)',
                    (string) $cell['member_count'],
                    (string) ($cell['recent_sessions'] ?? 0),
                    (string) ($cell['recent_attendance_rate'] ?? ''),
                    $cell['last_session_date'] ?? '—',
                    implode('; ', $cell['health_flags'] ?? []),
                ]));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['Summary']));
            $writer->addRow(Row::fromValues(['Total Cells', (string) $data['summary']['total_cells']]));
            $writer->addRow(Row::fromValues(['Cells with Leader', (string) $data['summary']['cells_with_leader']]));
            $writer->addRow(Row::fromValues(['Cells with Recent Attendance', (string) $data['summary']['cells_with_recent_attendance']]));
            $writer->addRow(Row::fromValues(['Total Members', (string) $data['summary']['total_members']]));
            $writer->addRow(Row::fromValues(['Avg Members per Cell', (string) $data['summary']['avg_members_per_cell']]));
            $writer->addRow(Row::fromValues(['Avg Attendance Rate (%)', (string) $data['summary']['avg_attendance_rate']]));

            $writer->close();
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // MEMBER WELFARE REPORT — engagement tracking for leadership
    // ──────────────────────────────────────────────────────────────────

    // GET /api/reports/members/welfare
    //
    // Per-member engagement data: attendance rate, giving activity,
    // welfare flag. Cell leaders see own cell; pastors/admins see all.
    //
    // Query params:
    //   cell_id        (optional, filter to a single cell)
    //   welfare_status (optional, filter by welfare flag)
    public function memberWelfare(Request $request): JsonResponse
    {
        return response()->json($this->buildMemberWelfareData($request));
    }

    /**
     * Build the member-welfare dataset. Used by JSON, PDF, and CSV
     * export endpoints.
     */
    protected function buildMemberWelfareData(Request $request): array
    {
        $validated = $request->validate([
            'cell_id' => 'nullable|uuid|exists:cells,id',
            'welfare_status' => 'nullable|in:engaged,moderate,at_risk,inactive_risk,none,all',
        ]);

        $branchId = $request->user()->branch_id;

        // Cell leaders are scoped to their own cells
        $cellId = $validated['cell_id'] ?? null;
        if (! $cellId && $request->user()->hasRole('cell_leader')) {
            $userCellIds = Cell::where('leader_user_id', $request->user()->id)
                ->pluck('id')
                ->toArray();
            $cellId = $userCellIds[0] ?? null;
        }

        return $this->welfareService->getWelfare(
            $branchId,
            $cellId,
            $validated['welfare_status'] ?? null,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // CHURCH ATTENDANCE SUMMARY EXPORTS
    // ──────────────────────────────────────────────────────────────────

    // GET /api/reports/attendance/summary/export-pdf
    public function attendanceSummaryPdf(Request $request)
    {
        $data = $this->buildAttendanceSummaryData($request);
        $branch = Branch::find($request->user()->branch_id);

        $pdf = Pdf::loadView('pdf.report-attendance-summary', [
            'data' => $data,
            'branchName' => $branch?->name ?? 'Wesleyan International Society',
            'generatedAt' => now()->format('F j, Y \\a\\t g:i a'),
        ]);

        $from = $data['period']['from'];
        $to = $data['period']['to'];

        return $pdf->download("attendance-summary-{$from}-to-{$to}.pdf");
    }

    // GET /api/reports/attendance/summary/export-xlsx
    //
    // Multi-tab Excel export for Leaders' Meeting:
    //   Tab 1: Executive Summary — KPIs and welfare overview
    //   Tab 2: Cell Breakdown — per-Cell attendance, rates, welfare
    //   Tab 3: State of Members — welfare flag distribution
    //   Tab 4: Weekly Trend — per-Sunday attendance
    public function attendanceSummaryXlsx(Request $request)
    {
        $data = $this->buildAttendanceSummaryData($request);
        $from = $data['period']['from'];
        $to = $data['period']['to'];
        $branch = Branch::find($request->user()->branch_id);
        $branchName = $branch?->name ?? 'Wesleyan International Society';
        $filename = "leaders-meeting-report-{$from}-to-{$to}.xlsx";

        // Styles
        $titleStyle = new Style(fontBold: true, fontSize: 16);
        $sectionStyle = new Style(fontBold: true, fontSize: 13);
        $headerStyle = new Style(fontBold: true, fontSize: 11, backgroundColor: 'FFD9E1F2');
        $boldStyle = new Style(fontBold: true);
        $pctStyle = new Style(format: '0.0%');

        return new StreamedResponse(function () use (
            $data, $branchName, $from, $to,
            $titleStyle, $sectionStyle, $headerStyle, $boldStyle, $pctStyle,
        ) {
            $options = new XlsxOptions(DEFAULT_COLUMN_WIDTH: 18);
            $writer = new Writer($options);
            $writer->openToFile('php://output');

            // ════════════════════════════════════════════════════════
            // TAB 1: EXECUTIVE SUMMARY
            // ════════════════════════════════════════════════════════
            $writer->addRow(Row::fromValuesWithStyle([$branchName], $titleStyle));
            $writer->addRow(Row::fromValuesWithStyle(["Leaders' Meeting Report — Church Attendance Summary"], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues([
                'Period:',
                Carbon::parse($from)->format('F j, Y').' — '.Carbon::parse($to)->format('F j, Y'),
            ]));
            $writer->addRow(Row::fromValues(['Report Date:', now()->format('F j, Y')]));
            $writer->addRow(Row::fromValues(['']));

            $writer->addRow(Row::fromValuesWithStyle(['KEY PERFORMANCE INDICATORS'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValuesWithStyle(['Metric', 'Value'], $headerStyle));
            $writer->addRow(Row::fromValues(['Overall Attendance Rate', $data['summary']['overall_attendance_rate'] / 100]));
            $writer->addRow(Row::fromValues(['Average Attendance / Sunday', $data['summary']['avg_per_sunday']]));
            $writer->addRow(Row::fromValues(['Total Attendance', $data['summary']['total_attendance']]));
            $writer->addRow(Row::fromValues(['Sundays Covered', $data['summary']['total_sundays']]));
            $writer->addRow(Row::fromValues(['Active Members', $data['summary']['total_active_members']]));
            $writer->addRow(Row::fromValues(['Average Adults / Sunday', $data['summary']['avg_adults']]));
            $writer->addRow(Row::fromValues(['Average Children / Sunday', $data['summary']['avg_children']]));
            if ($data['summary']['highest_sunday']) {
                $writer->addRow(Row::fromValues(['Highest Sunday', $data['summary']['highest_sunday'].' ('.$data['summary']['highest_count'].')']));
            }
            if ($data['summary']['lowest_sunday']) {
                $writer->addRow(Row::fromValues(['Lowest Sunday', $data['summary']['lowest_sunday'].' ('.$data['summary']['lowest_count'].')']));
            }
            $trend = $data['summary']['trend'] ?? null;
            if ($trend && $trend['direction'] !== 'unknown') {
                $label = match ($trend['direction']) {
                    'up' => 'Improving',
                    'down' => 'Declining',
                    default => 'Stable',
                };
                $writer->addRow(Row::fromValues(['Trend', $label.' ('.($trend['delta'] > 0 ? '+' : '').$trend['delta'].' avg)']));
            }
            $writer->addRow(Row::fromValues(['']));

            // Welfare summary on Tab 1
            $ws = $data['summary']['welfare_summary'];
            $totalM = $ws['engaged'] + $ws['moderate'] + $ws['at_risk'] + $ws['inactive_risk'];
            $writer->addRow(Row::fromValuesWithStyle(['WELFARE SNAPSHOT'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValuesWithStyle(['Status', 'Count', 'Share'], $headerStyle));
            foreach (['engaged', 'moderate', 'at_risk', 'inactive_risk'] as $flag) {
                $writer->addRow(Row::fromValues([
                    ucfirst(str_replace('_', ' ', $flag)),
                    $ws[$flag],
                    $totalM > 0 ? $ws[$flag] / $totalM : 0,
                ]));
            }
            $writer->addRow(Row::fromValues(['TOTAL', $totalM, 1.0]));
            $writer->addRow(Row::fromValues(['']));

            if (! empty($data['summary']['cells_at_risk'])) {
                $writer->addRow(Row::fromValuesWithStyle(['CELLS REQUIRING ATTENTION'], $sectionStyle));
                $writer->addRow(Row::fromValues(['Cells with attendance rate below 50%:']));
                foreach ($data['summary']['cells_at_risk'] as $cellName) {
                    $writer->addRow(Row::fromValues(['  • '.$cellName]));
                }
            }

            // ════════════════════════════════════════════════════════
            // TAB 2: CELL / CLASS BREAKDOWN
            // ════════════════════════════════════════════════════════
            $writer->addNewSheet();
            $writer->setCurrentSheet(1);
            $writer->addRow(Row::fromValuesWithStyle(['Cell / Class Breakdown'], $titleStyle));
            $writer->addRow(Row::fromValues([
                Carbon::parse($from)->format('F j, Y').' — '.Carbon::parse($to)->format('F j, Y'),
            ]));
            $writer->addRow(Row::fromValues(['']));

            $writer->addRow(Row::fromValuesWithStyle([
                'Cell / Class', 'Members', 'Avg Attendance', 'Attendance Rate',
                'Contribution (%)', 'Engaged', 'Moderate', 'At Risk', 'Inactive Risk',
                'Pastoral Notes (4wk)',
            ], $headerStyle));

            $grandTotal = array_sum(array_column($data['cell_summary'], 'avg_attendance'));
            foreach ($data['cell_summary'] as $cell) {
                $contribution = $grandTotal > 0 ? $cell['avg_attendance'] / $grandTotal : 0;
                $writer->addRow(Row::fromValuesWithStyle([
                    $cell['name'],
                    $cell['member_count'],
                    $cell['avg_attendance'],
                    $cell['attendance_rate'] !== null ? $cell['attendance_rate'] / 100 : null,
                    $contribution,
                    $cell['welfare_distribution']['engaged'],
                    $cell['welfare_distribution']['moderate'],
                    $cell['welfare_distribution']['at_risk'],
                    $cell['welfare_distribution']['inactive_risk'],
                    $cell['recent_pastoral_notes_count'],
                ], $pctStyle));
            }

            // ════════════════════════════════════════════════════════
            // TAB 3: STATE OF THE MEMBERS
            // ════════════════════════════════════════════════════════
            $writer->addNewSheet();
            $writer->setCurrentSheet(2);
            $writer->addRow(Row::fromValuesWithStyle(['State of the Members'], $titleStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValuesWithStyle(['Cell / Class', 'Members', 'Engaged', 'Moderate', 'At Risk', 'Inactive Risk', 'Pastoral Notes'], $headerStyle));

            foreach ($data['cell_summary'] as $cell) {
                $writer->addRow(Row::fromValues([
                    $cell['name'],
                    $cell['member_count'],
                    $cell['welfare_distribution']['engaged'],
                    $cell['welfare_distribution']['moderate'],
                    $cell['welfare_distribution']['at_risk'],
                    $cell['welfare_distribution']['inactive_risk'],
                    $cell['recent_pastoral_notes_count'],
                ]));
            }
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValuesWithStyle(['BRANCH TOTAL', $totalM, $ws['engaged'], $ws['moderate'], $ws['at_risk'], $ws['inactive_risk']], $boldStyle));

            // ════════════════════════════════════════════════════════
            // TAB 4: WEEKLY TREND
            // ════════════════════════════════════════════════════════
            $writer->addNewSheet();
            $writer->setCurrentSheet(3);
            $writer->addRow(Row::fromValuesWithStyle(['Weekly Attendance Trend'], $titleStyle));
            $writer->addRow(Row::fromValues([
                Carbon::parse($from)->format('F j, Y').' — '.Carbon::parse($to)->format('F j, Y'),
            ]));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValuesWithStyle(['Sunday', 'Adults', 'Children', 'Total', 'Attendance Rate'], $headerStyle));

            foreach ($data['sundays'] as $sunday) {
                $rate = $data['summary']['total_active_members'] > 0
                    ? $sunday['total_count'] / $data['summary']['total_active_members']
                    : 0;
                $writer->addRow(Row::fromValuesWithStyle([
                    $sunday['date_label'],
                    $sunday['adult_count'],
                    $sunday['children_count'],
                    $sunday['total_count'],
                    $rate,
                ], $pctStyle));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // MEMBER WELFARE EXPORTS
    // ──────────────────────────────────────────────────────────────────

    // GET /api/reports/members/welfare/export-pdf
    public function memberWelfarePdf(Request $request)
    {
        $data = $this->buildMemberWelfareData($request);
        $branch = Branch::find($request->user()->branch_id);

        $pdf = Pdf::loadView('pdf.report-member-welfare', [
            'data' => $data,
            'branchName' => $branch?->name ?? 'Wesleyan International Society',
            'generatedAt' => now()->format('F j, Y \\a\\t g:i a'),
        ]);

        $from = $data['period']['from'];
        $to = $data['period']['to'];

        return $pdf->download("member-welfare-{$from}-to-{$to}.pdf");
    }

    // GET /api/reports/members/welfare/export-csv
    public function memberWelfareCsv(Request $request)
    {
        $data = $this->buildMemberWelfareData($request);
        $from = $data['period']['from'];
        $to = $data['period']['to'];
        $filename = "member-welfare-{$from}-to-{$to}.csv";

        return new StreamedResponse(function () use ($data) {
            $writer = new Writer;
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues([
                'Name', 'Member No.', 'Cell', 'Welfare Flag',
                'Attendance Rate (%)', 'Attended', 'Total Sundays',
                'Giving Total', 'Last Attendance',
            ]));
            foreach ($data['members'] as $member) {
                $writer->addRow(Row::fromValues([
                    $member['name'],
                    $member['member_number'],
                    $member['cell_name'],
                    $member['welfare_flag'],
                    (string) $member['attendance_rate'],
                    (string) $member['attended_services'],
                    (string) $member['total_sundays_in_window'],
                    (string) $member['giving_total'],
                    $member['last_attendance_date'] ?? '—',
                ]));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['Summary']));
            $writer->addRow(Row::fromValues(['Total Members', (string) $data['summary']['total_members']]));
            $writer->addRow(Row::fromValues(['Avg Attendance Rate (%)', (string) $data['summary']['avg_attendance_rate']]));
            foreach ($data['summary']['flag_counts'] as $flag => $count) {
                $writer->addRow(Row::fromValues(["Flag: {$flag}", (string) $count]));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
