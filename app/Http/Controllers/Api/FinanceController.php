<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreTransactionRequest;
use App\Http\Requests\Finance\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Services\FinanceStatsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(private readonly FinanceStatsService $statsService) {}

    // GET /api/finance/transactions
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()->with(['category', 'member', 'recorder']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%")
                    ->orWhereHas('member', fn ($m) => $m
                        ->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                    );
            });
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }
        if ($from = $request->input('from')) {
            $query->whereDate('transaction_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('transaction_date', '<=', $to);
        }

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => TransactionResource::collection($transactions->items()),
            'meta' => [
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    // GET /api/finance/categories
    public function categories(Request $request): JsonResponse
    {
        $query = FinanceCategory::where('is_active', true)->orderBy('display_order')->orderBy('name');

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/finance/transactions
     *
     * MEDIUM-01 FIX: Controller now uses $request->validated() exclusively.
     * The old code overrode 'currency' with $request->input('currency', 'GHS'),
     * bypassing the Form Request's max:3/in: rules. The default is now
     * injected in StoreTransactionRequest::prepareForValidation() so
     * validated() always contains a valid currency string.
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = Transaction::create([
            ...$request->validated(),
            'branch_id' => $request->user()->branch_id,
            'recorded_by' => $request->user()->id,
        ]);

        activity()->causedBy($request->user())
            ->performedOn($transaction)
            ->log("Recorded {$transaction->type} of GHS ".number_format((float) $transaction->amount, 2));

        return response()->json([
            'message' => 'Transaction recorded successfully.',
            'data' => new TransactionResource($transaction->load('category', 'member', 'recorder')),
        ], 201);
    }

    // GET /api/finance/transactions/{id}
    public function show(string $id): JsonResponse
    {
        $transaction = Transaction::with(['category', 'member', 'recorder'])->findOrFail($id);

        return response()->json(['data' => new TransactionResource($transaction)]);
    }

    // PUT /api/finance/transactions/{id}
    public function update(UpdateTransactionRequest $request, string $id): JsonResponse
    {
        $transaction = Transaction::findOrFail($id);
        $transaction->update($request->validated());

        activity()->causedBy($request->user())
            ->performedOn($transaction)
            ->log('Updated transaction #'.substr($transaction->id, 0, 8));

        return response()->json([
            'message' => 'Transaction updated successfully.',
            'data' => new TransactionResource($transaction->load('category', 'member', 'recorder')),
        ]);
    }

    // DELETE /api/finance/transactions/{id}
    public function destroy(Request $request, string $id): JsonResponse
    {
        $transaction = Transaction::findOrFail($id);
        $transaction->delete();

        activity()->causedBy($request->user())
            ->log('Deleted transaction of GHS '.number_format($transaction->amount, 2));

        return response()->json(['message' => 'Transaction deleted successfully.']);
    }

    // GET /api/finance/transactions/export
    public function export(Request $request): StreamedResponse
    {
        $query = Transaction::query()->with(['category', 'member']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%");
            });
        }
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }
        if ($from = $request->input('from')) {
            $query->whereDate('transaction_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('transaction_date', '<=', $to);
        }

        $query->orderByDesc('transaction_date');
        $filename = 'transactions-'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($query) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['Date', 'Type', 'Category', 'Amount', 'Currency', 'Member', 'Reference', 'Notes']));

            $query->lazy()->each(function ($t) use ($writer) {
                $writer->addRow(Row::fromValues([
                    optional($t->transaction_date)->toDateString(),
                    ucfirst($t->type),
                    $t->category?->name ?? '',
                    (string) $t->amount,
                    $t->currency ?? 'GHS',
                    $t->member?->full_name ?? '',
                    $t->reference,
                    $t->notes,
                ]));
            });

            $writer->close();
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // GET /api/finance/reports/ledger
    public function ledger(Request $request): mixed
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->input('from');
        $to = $request->input('to');

        $transactions = Transaction::query()
            ->whereBetween('transaction_date', [$from, $to])
            ->with('category')
            ->orderBy('transaction_date')
            ->get();

        $groupByCategory = fn ($items) => $items
            ->groupBy(fn ($t) => $t->category?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'category' => $group->first()->category?->name ?? 'Uncategorised',
                'count' => $group->count(),
                'total' => round($group->sum('amount'), 2),
            ])
            ->sortByDesc('total')
            ->values();

        $income = $transactions->where('type', 'income');
        $expense = $transactions->where('type', 'expense');

        $totalIncome = round($income->sum('amount'), 2);
        $totalExpense = round($expense->sum('amount'), 2);

        $branch = Branch::find($request->user()->branch_id);

        $pdf = Pdf::loadView('pdf.financial-ledger', [
            'period' => ['from' => $from, 'to' => $to],
            'incomeByCategory' => $groupByCategory($income),
            'expenseByCategory' => $groupByCategory($expense),
            'totalIncome' => $totalIncome,
            'totalExpense' => $totalExpense,
            'net' => round($totalIncome - $totalExpense, 2),
            'branchName' => $branch?->name ?? 'Wesleyan International Society',
            'generatedAt' => now()->format('F j, Y'),
        ]);

        return $pdf->download("financial-ledger-{$from}-to-{$to}.pdf");
    }

    /**
     * GET /api/finance/stats
     *
     * PERF-02 FIX: Delegates to FinanceStatsService, which uses 3 aggregated
     * queries instead of the original 16+. The 12-query-per-chart loop is
     * replaced by a single GROUP BY query.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'data' => $this->statsService->getStats(),
        ]);
    }
}
