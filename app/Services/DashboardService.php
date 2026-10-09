<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\Visitor;
use App\Support\DateRange;
use Carbon\Carbon;

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

        // ─── Q2: Last session with pre-aggregated counts (view) ─────────────
        // total_count comes from attendance_session_counts — one query for
        // the whole page, correct for both register and headcount sessions.
        $lastSession = AttendanceSession::query()
            ->with('counts')
            ->latest('service_date')
            ->first();

        // ─── Q3: Current + previous month income in one aggregated query ──────
        $prevMonth = $now->copy()->subMonth();
        [$monthFrom, $monthTo] = DateRange::month($now);
        [$prevFrom, $prevTo] = DateRange::month($prevMonth);

        $incomeAgg = Transaction::query()
            ->where('type', 'income')
            ->where('transaction_date', '>=', $prevFrom)
            ->where('transaction_date', '<', $monthTo)
            ->selectRaw('
                COALESCE(SUM(amount) FILTER (
                    WHERE transaction_date >= ? AND transaction_date < ?
                ), 0) AS current_month,
                COALESCE(SUM(amount) FILTER (
                    WHERE transaction_date >= ? AND transaction_date < ?
                ), 0) AS prev_month
            ', [
                $monthFrom->toDateString(), $monthTo->toDateString(),
                $prevFrom->toDateString(), $prevTo->toDateString(),
            ])
            ->first();

        $monthIncome = (float) ($incomeAgg->current_month ?? 0);
        $prevIncome = (float) ($incomeAgg->prev_month ?? 0);
        $incomeGrowth = $prevIncome > 0
            ? round((($monthIncome - $prevIncome) / $prevIncome) * 100, 1)
            : null;

        // ─── Q4: Visitor count this month ─────────────────────────────────────
        $monthVisitors = Visitor::query()
            ->where('visit_date', '>=', $monthFrom)
            ->where('visit_date', '<', $monthTo)
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

        // ─── Q6: Attendance chart — last 8 distinct dates, total per date.
        // The dates are resolved first so the session load stays bounded
        // at 8 days of rows instead of hydrating the whole table.
        $recentDates = AttendanceSession::query()
            ->select('service_date')
            ->distinct()
            ->orderByDesc('service_date')
            ->limit(8)
            ->pluck('service_date');

        $attendanceChart = AttendanceSession::query()
            ->with('counts')
            ->whereIn('service_date', $recentDates)
            ->latest('service_date')
            ->get()
            ->groupBy(fn ($s) => $s->service_date->toDateString())
            ->reverse()
            ->map(fn ($sessionsOnDate, string $date) => [
                'date' => Carbon::parse($date)->format('d M'),
                'count' => $sessionsOnDate->sum(fn ($s) => $s->total_count),
            ])
            ->values();

        // ─── Q7: Top income categories this month ─────────────────────────────
        $topCategories = Transaction::query()
            ->where('transaction_date', '>=', $monthFrom)
            ->where('transaction_date', '<', $monthTo)
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
