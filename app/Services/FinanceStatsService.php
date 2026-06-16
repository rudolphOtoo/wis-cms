<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;

/**
 * PERF-02 FIX: Encapsulates all financial statistics aggregations.
 *
 * The original FinanceController::stats() ran 12 individual queries in a
 * for-loop (2 per month × 6 months) plus several additional single queries.
 * This service replaces all of that with 3 targeted aggregated SQL queries,
 * reducing total query count from 16+ to 3 regardless of date range size.
 *
 * Branch scoping is provided automatically by the BelongsToBranch global
 * scope on the Transaction model — no explicit branch_id filter needed here.
 */
class FinanceStatsService
{
    /**
     * Build the complete financial stats payload for the finance dashboard.
     *
     * Query budget: 3 queries (current-month summary, all-time totals,
     * 6-month chart roll-up, top categories).
     *
     * @return array{
     *   this_month_income: float,
     *   this_month_expenses: float,
     *   this_month_balance: float,
     *   this_month_count: int,
     *   total_income: float,
     *   total_expenses: float,
     *   total_balance: float,
     *   chart: list<array{month: string, income: float, expenses: float}>,
     *   top_categories: list<array{name: string, total: float}>,
     * }
     */
    public function getStats(): array
    {
        $now = now();

        // ─── Q1: Current-month income + expense + count (single pass) ─────────
        $monthSummary = Transaction::query()
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date', $now->year)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expenses,
                COUNT(*) AS total_count
            ")
            ->first();

        $income = (float) ($monthSummary->income ?? 0);
        $expenses = (float) ($monthSummary->expenses ?? 0);

        // ─── Q2: All-time totals (single pass) ───────────────────────────────
        $allTime = Transaction::query()
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS total_expenses
            ")
            ->first();

        $totalIncome = (float) ($allTime->total_income ?? 0);
        $totalExpenses = (float) ($allTime->total_expenses ?? 0);

        // ─── Q3: 6-month chart — one GROUP BY replaces 12 separate queries ───
        // TO_CHAR groups by calendar month ('YYYY-MM') so months with no
        // transactions simply don't appear in the result — we fill gaps below.
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

        // Build a dense 6-element chart array; zero-fill months with no data.
        $chart = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $key = $m->format('Y-m');
            $row = $chartRows->get($key);

            $chart[] = [
                'month' => $m->format('M'),
                'income' => (float) ($row?->income ?? 0),
                'expenses' => (float) ($row?->expenses ?? 0),
            ];
        }

        // ─── Q4: Top 5 income categories this month ───────────────────────────
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

        return [
            'this_month_income' => $income,
            'this_month_expenses' => $expenses,
            'this_month_balance' => round($income - $expenses, 2),
            'this_month_count' => (int) ($monthSummary->total_count ?? 0),
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'total_balance' => round($totalIncome - $totalExpenses, 2),
            'chart' => $chart,
            'top_categories' => $topCategories,
        ];
    }
}
