<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
