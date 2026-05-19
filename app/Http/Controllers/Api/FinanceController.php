<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreTransactionRequest;
use App\Http\Requests\Finance\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\FinanceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    // GET /api/finance/transactions
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()
            ->where('branch_id', $request->user()->branch_id)
            ->with(['category', 'member', 'recorder']);

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'ilike', "%{$search}%")
                  ->orWhere('notes',    'ilike', "%{$search}%")
                  ->orWhereHas('member', fn($m) =>
                      $m->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                  );
            });
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        if ($categoryId = $request->get('category_id')) {
            $query->where('category_id', $categoryId);
        }

        if ($from = $request->get('from')) {
            $query->whereDate('transaction_date', '>=', $from);
        }

        if ($to = $request->get('to')) {
            $query->whereDate('transaction_date', '<=', $to);
        }

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => TransactionResource::collection($transactions->items()),
            'meta' => [
                'total'        => $transactions->total(),
                'per_page'     => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
            ],
        ]);
    }

    // GET /api/finance/categories
    public function categories(Request $request): JsonResponse
    {
        $type = $request->get('type'); // income | expense | null
        $query = FinanceCategory::where('is_active', true)->orderBy('name');
        if ($type) $query->where('type', $type);

        return response()->json(['data' => $query->get()]);
    }

    // POST /api/finance/transactions
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = Transaction::create([
            ...$request->validated(),
            'branch_id'   => $request->user()->branch_id,
            'currency'    => $request->get('currency', 'GHS'),
            'recorded_by' => $request->user()->id,
        ]);

        activity()->causedBy($request->user())
                  ->performedOn($transaction)
                  ->log("Recorded {$transaction->type} of GHS " . number_format($transaction->amount, 2));

        return response()->json([
            'message' => 'Transaction recorded successfully.',
            'data'    => new TransactionResource($transaction->load('category', 'member', 'recorder')),
        ], 201);
    }

    // GET /api/finance/transactions/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $transaction = Transaction::where('branch_id', $request->user()->branch_id)
            ->with(['category', 'member', 'recorder'])
            ->findOrFail($id);

        return response()->json(['data' => new TransactionResource($transaction)]);
    }

    // PUT /api/finance/transactions/{id}
    public function update(UpdateTransactionRequest $request, string $id): JsonResponse
    {
        $transaction = Transaction::where('branch_id', $request->user()->branch_id)
            ->findOrFail($id);

        $transaction->update($request->validated());

        activity()->causedBy($request->user())
                  ->performedOn($transaction)
                  ->log("Updated transaction #" . substr($transaction->id, 0, 8));

        return response()->json([
            'message' => 'Transaction updated successfully.',
            'data'    => new TransactionResource($transaction->load('category', 'member', 'recorder')),
        ]);
    }

    // DELETE /api/finance/transactions/{id}
    public function destroy(Request $request, string $id): JsonResponse
    {
        $transaction = Transaction::where('branch_id', $request->user()->branch_id)
            ->findOrFail($id);

        $transaction->delete();

        activity()->causedBy($request->user())
                  ->log("Deleted transaction of GHS " . number_format($transaction->amount, 2));

        return response()->json(['message' => 'Transaction deleted successfully.']);
    }

    // GET /api/finance/stats
    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        $now      = now();

        $thisMonth = Transaction::where('branch_id', $branchId)
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date',  $now->year);

        $income     = (clone $thisMonth)->where('type', 'income')->sum('amount');
        $expenses   = (clone $thisMonth)->where('type', 'expense')->sum('amount');
        $balance    = $income - $expenses;
        $totalCount = (clone $thisMonth)->count();

        // Total income/expenses all time
        $totalIncome   = Transaction::where('branch_id', $branchId)->where('type','income')->sum('amount');
        $totalExpenses = Transaction::where('branch_id', $branchId)->where('type','expense')->sum('amount');

        // Last 6 months chart
        $chart = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $monthIncome = Transaction::where('branch_id', $branchId)
                ->where('type', 'income')
                ->whereMonth('transaction_date', $month->month)
                ->whereYear('transaction_date',  $month->year)
                ->sum('amount');
            $monthExpenses = Transaction::where('branch_id', $branchId)
                ->where('type', 'expense')
                ->whereMonth('transaction_date', $month->month)
                ->whereYear('transaction_date',  $month->year)
                ->sum('amount');

            $chart[] = [
                'month'    => $month->format('M'),
                'income'   => (float) $monthIncome,
                'expenses' => (float) $monthExpenses,
            ];
        }

        // Top categories this month
        $topCategories = Transaction::where('branch_id', $branchId)
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date',  $now->year)
            ->where('type', 'income')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->take(5)
            ->with('category:id,name')
            ->get()
            ->map(fn($t) => [
                'name'  => $t->category?->name,
                'total' => (float) $t->total,
            ]);

        return response()->json([
            'data' => [
                'this_month_income'   => (float) $income,
                'this_month_expenses' => (float) $expenses,
                'this_month_balance'  => (float) $balance,
                'this_month_count'    => $totalCount,
                'total_income'        => (float) $totalIncome,
                'total_expenses'      => (float) $totalExpenses,
                'total_balance'       => (float) ($totalIncome - $totalExpenses),
                'chart'               => $chart,
                'top_categories'      => $topCategories,
            ],
        ]);
    }
}
