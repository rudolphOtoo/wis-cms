<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\Visitor;

/**
 * SMELL-01 FIX: Extracts the admin dashboard aggregation out of
 * DashboardController, which previously ran 13+ direct database queries
 * inline, making it untestable in isolation and difficult to cache.
 *
 * Query budget: 7 queries, down from 13+.
 *   Q1  — member stats (total, male, female) via a single FILTER aggregate
 *   Q2  — last adult attendance session (with records eager-loaded)
 *   Q3  — current + previous month income (2-in-1 via CASE aggregation)
 *   Q4  — month visitors count
 *   Q5  — 6-month finance chart (single GROUP BY replaces 12 queries)
 *   Q6  — attendance chart (8 sessions, records eager-loaded for adult_count)
 *   Q7  — top income categories this month
 *   + 2 lightweight count queries (departments, pending visitors)
 *   + 2 recent-activity queries (recent members, recent transactions)
 *
 * Branch scoping: the BelongsToBranch global scope on Member, Transaction,
 * AttendanceSession, etc. handles branch filtering automatically for all
 * Eloquent queries. No branch_id argument is needed — the authenticated
 * user's branch is applied via the scope.
 */
class DashboardService
{
    /**
     * Build the complete admin dashboard payload.
     *
     * @return array<string, mixed>
     */
    public function getAdminStats(): array
    {
        $now = now();

        // ─── Q1: Member counts — one pass for total, male, female ─────────────
        $memberStats = Member::query()
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'active')                        AS total_active,
                COUNT(*) FILTER (WHERE status = 'active' AND gender = 'male')    AS male,
                COUNT(*) FILTER (WHERE status = 'active' AND gender = 'female')  AS female
            ")
            ->first();

        // ─── Q2: Last session with records pre-loaded (adult + children) ────
        // total_count uses the in-memory records collection (PERF-01 fixed).
        $lastSession = AttendanceSession::query()
            ->with('records')
            ->latest('service_date')
            ->first();

        // ─── Q3: Current + previous month income in one aggregated query ──────
        $prevMonth = $now->copy()->subMonth();

        $incomeAgg = Transaction::query()
            ->where('type', 'income')
            ->where(function ($q) use ($now, $prevMonth) {
                $q->where(function ($inner) use ($now) {
                    $inner->whereMonth('transaction_date', $now->month)
                        ->whereYear('transaction_date', $now->year);
                })->orWhere(function ($inner) use ($prevMonth) {
                    $inner->whereMonth('transaction_date', $prevMonth->month)
                        ->whereYear('transaction_date', $prevMonth->year);
                });
            })
            ->selectRaw('
                COALESCE(SUM(amount) FILTER (
                    WHERE EXTRACT(MONTH FROM transaction_date) = ?
                      AND EXTRACT(YEAR  FROM transaction_date) = ?
                ), 0) AS current_month,
                COALESCE(SUM(amount) FILTER (
                    WHERE EXTRACT(MONTH FROM transaction_date) = ?
                      AND EXTRACT(YEAR  FROM transaction_date) = ?
                ), 0) AS prev_month
            ', [$now->month, $now->year, $prevMonth->month, $prevMonth->year])
            ->first();

        $monthIncome = (float) ($incomeAgg->current_month ?? 0);
        $prevIncome = (float) ($incomeAgg->prev_month ?? 0);
        $incomeGrowth = $prevIncome > 0
            ? round((($monthIncome - $prevIncome) / $prevIncome) * 100, 1)
            : null;

        // ─── Q4: Visitor count this month ─────────────────────────────────────
        $monthVisitors = Visitor::query()
            ->whereMonth('visit_date', $now->month)
            ->whereYear('visit_date', $now->year)
            ->count();

        // ─── Q5: 6-month finance chart (1 GROUP BY query, not 12) ─────────────
        $sixMonthsAgo = $now->copy()->subMonths(5)->startOfMonth();
        $chartRows = Transaction::query()
            ->where('transaction_date', '>=', $sixMonthsAgo)
            ->selectRaw("
                TO_CHAR(transaction_date, 'YYYY-MM') AS month_key,
                COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expenses
            ")
            ->groupByRaw("TO_CHAR(transaction_date, 'YYYY-MM')")
            ->get()
            ->keyBy('month_key');

        $financeChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $key = $m->format('Y-m');
            $row = $chartRows->get($key);
            $financeChart[] = [
                'month' => $m->format('M'),
                'income' => (float) ($row?->income ?? 0),
                'expenses' => (float) ($row?->expenses ?? 0),
            ];
        }

        // ─── Q6: Attendance chart — last 8 distinct dates, total per date
        $attendanceChart = AttendanceSession::query()
            ->with('records')
            ->latest('service_date')
            ->get()
            ->groupBy(fn ($s) => $s->service_date->toDateString())
            ->take(8)
            ->reverse()
            ->map(fn ($sessionsOnDate, string $date) => [
                'date' => Carbon::parse($date)->format('d M'),
                'count' => $sessionsOnDate->sum(fn ($s) => $s->total_count),
            ])
            ->values();

        // ─── Q7: Top income categories this month ─────────────────────────────
        $topCategories = Transaction::query()
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date', $now->year)
            ->where('type', 'income')
            ->selectRaw('category_id, SUM(amount) AS total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->take(5)
            ->with('category:id,name')
            ->get()
            ->map(fn ($t) => [
                'name' => $t->category?->name ?? 'Uncategorised',
                'total' => (float) $t->total,
            ]);

        // ─── Lightweight counters ─────────────────────────────────────────────
        $counts = [
            'departments' => Department::query()->where('is_active', true)->count(),
            'pending_visitors' => Visitor::query()->where('follow_up_status', 'pending')->count(),
        ];

        // ─── Recent activity ─────────────────────────────────────────────────
        $recentMembers = Member::query()
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($m) => [
                'name' => $m->full_name,
                'detail' => $m->member_number,
                'when' => $m->created_at->diffForHumans(),
            ]);

        $recentTransactions = Transaction::query()
            ->with(['category', 'member'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($t) => [
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'detail' => $t->category?->name.($t->member ? ' · '.$t->member->full_name : ''),
                'when' => $t->created_at->diffForHumans(),
            ]);

        return [
            'hero' => [
                'total_members' => (int) ($memberStats->total_active ?? 0),
                'last_attendance' => $lastSession?->total_count ?? 0,
                'month_income' => $monthIncome,
                'month_visitors' => $monthVisitors,
                'income_growth' => $incomeGrowth,
            ],
            'gender_split' => [
                'male' => (int) ($memberStats->male ?? 0),
                'female' => (int) ($memberStats->female ?? 0),
            ],
            'counts' => $counts,
            'attendance_chart' => $attendanceChart,
            'finance_chart' => $financeChart,
            'top_categories' => $topCategories,
            'recent_members' => $recentMembers,
            'recent_transactions' => $recentTransactions,
        ];
    }
}
