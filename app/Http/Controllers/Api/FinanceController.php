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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer;
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

        $branch = Branch::find($request->user()->branch_id);
        $branchName = $branch?->name ?? 'Wesleyan International Society';

        $allTransactions = $query->get();
        $firstDate = $allTransactions->min('transaction_date');
        $lastDate = $allTransactions->max('transaction_date');
        $periodFrom = $firstDate ? Carbon::parse($firstDate)->format('M j, Y') : '—';
        $periodTo = $lastDate ? Carbon::parse($lastDate)->format('M j, Y') : '—';

        $totalIncome = (float) $allTransactions->where('type', 'income')->sum('amount');
        $totalExpense = (float) $allTransactions->where('type', 'expense')->sum('amount');
        $net = $totalIncome - $totalExpense;

        $categoryStats = $allTransactions
            ->groupBy('type')
            ->map(fn ($txns) => $txns
                ->groupBy(fn ($t) => $t->category?->name ?? '(Uncategorized)')
                ->map(fn ($group, $name) => [
                    'name' => $name,
                    'count' => $group->count(),
                    'total' => (float) $group->sum('amount'),
                ])
                ->sortByDesc('total')
                ->values()
                ->all()
            );

        $monthlyTotals = $allTransactions
            ->groupBy(fn ($t) => Carbon::parse($t->transaction_date)->format('M Y'))
            ->map(fn ($txns) => [
                'income' => (float) $txns->where('type', 'income')->sum('amount'),
                'expense' => (float) $txns->where('type', 'expense')->sum('amount'),
            ])
            ->sortKeys()
            ->all();

        $filename = 'transactions-'.now()->format('Y-m-d').'.xlsx';
        $dateFmt = fn ($d) => $d ? Carbon::parse($d)->format('j M Y') : '';

        // Styles
        $titleStyle = new Style(fontBold: true, fontSize: 16);
        $sectionStyle = new Style(fontBold: true, fontSize: 13);
        $headerStyle = new Style(fontBold: true, fontSize: 11, backgroundColor: 'FFD9E1F2');
        $amountStyle = new Style(format: '"GHS "#,##0.00');
        $negAmountStyle = new Style(format: '"-GHS "#,##0.00');
        $boldStyle = new Style(fontBold: true);
        $boldAmountStyle = new Style(fontBold: true, format: '"GHS "#,##0.00');
        $boldNegAmountStyle = new Style(fontBold: true, format: '"-GHS "#,##0.00');

        return new StreamedResponse(function () use (
            $allTransactions, $branchName, $periodFrom, $periodTo,
            $totalIncome, $totalExpense, $net, $categoryStats, $monthlyTotals,
            $dateFmt, $titleStyle, $sectionStyle, $headerStyle,
            $amountStyle, $negAmountStyle, $boldStyle, $boldAmountStyle, $boldNegAmountStyle,
        ) {
            $options = new XlsxOptions(DEFAULT_COLUMN_WIDTH: 20);
            $writer = new Writer($options);
            $writer->openToFile('php://output');

            // ── SECTION 1: REPORT HEADER ──────────────────────────────
            $writer->addRow(Row::fromValuesWithStyle([$branchName], $titleStyle));
            $writer->addRow(Row::fromValuesWithStyle(['Transaction Report'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['Period:', $periodFrom.' to '.$periodTo]));
            $writer->addRow(Row::fromValues(['Report Date:', now()->format('F j, Y')]));
            $writer->addRow(Row::fromValues(['']));

            // ── SECTION 2: EXECUTIVE SUMMARY ──────────────────────────
            $writer->addRow(Row::fromValuesWithStyle(['EXECUTIVE SUMMARY'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValuesWithStyle(['Total Income', $totalIncome], $boldAmountStyle));
            $writer->addRow(Row::fromValuesWithStyle(['Total Expenses', $totalExpense], $boldAmountStyle));
            $netStyle = $net < 0 ? $boldNegAmountStyle : $boldAmountStyle;
            $writer->addRow(Row::fromValuesWithStyle(['Net Position', abs($net)], $netStyle));
            $writer->addRow(Row::fromValues(['Total Transactions', $allTransactions->count()]));
            $writer->addRow(Row::fromValues(['']));

            // ── SECTION 3: INCOME TRANSACTIONS ────────────────────────
            $incomeTransactions = $allTransactions->where('type', 'income');
            $writer->addRow(Row::fromValuesWithStyle(['INCOME TRANSACTIONS'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));

            if ($categoryStats->has('income') && count($categoryStats['income']) > 0) {
                $writer->addRow(Row::fromValuesWithStyle(['Category', 'Entries', 'Total (GHS)'], $headerStyle));
                foreach ($categoryStats['income'] as $cat) {
                    $writer->addRow(Row::fromValuesWithStyle([$cat['name'], $cat['count'], $cat['total']], $amountStyle));
                }
                $writer->addRow(Row::fromValuesWithStyle([
                    'Total Income', $incomeTransactions->count(), $totalIncome,
                ], $boldAmountStyle));
            } else {
                $writer->addRow(Row::fromValues(['No income transactions recorded.']));
            }
            $writer->addRow(Row::fromValues(['']));

            if ($incomeTransactions->isNotEmpty()) {
                $writer->addRow(Row::fromValuesWithStyle([
                    'Date', 'Member', 'Reference', 'Notes', 'Amount (GHS)',
                ], $headerStyle));
                foreach ($incomeTransactions as $t) {
                    $writer->addRow(Row::fromValuesWithStyle([
                        $dateFmt($t->transaction_date),
                        $t->member?->full_name ?? '',
                        $t->reference ?? '',
                        $t->notes ?? '',
                        (float) $t->amount,
                    ], $amountStyle));
                }
                $writer->addRow(Row::fromValues(['']));
            }

            // ── SECTION 4: EXPENSE TRANSACTIONS ───────────────────────
            $expenseTransactions = $allTransactions->where('type', 'expense');
            $writer->addRow(Row::fromValuesWithStyle(['EXPENSE TRANSACTIONS'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));

            if ($categoryStats->has('expense') && count($categoryStats['expense']) > 0) {
                $writer->addRow(Row::fromValuesWithStyle(['Category', 'Entries', 'Total (GHS)'], $headerStyle));
                foreach ($categoryStats['expense'] as $cat) {
                    $writer->addRow(Row::fromValuesWithStyle([$cat['name'], $cat['count'], $cat['total']], $amountStyle));
                }
                $writer->addRow(Row::fromValuesWithStyle([
                    'Total Expenses', $expenseTransactions->count(), $totalExpense,
                ], $boldAmountStyle));
            } else {
                $writer->addRow(Row::fromValues(['No expense transactions recorded.']));
            }
            $writer->addRow(Row::fromValues(['']));

            if ($expenseTransactions->isNotEmpty()) {
                $writer->addRow(Row::fromValuesWithStyle([
                    'Date', 'Member', 'Reference', 'Notes', 'Amount (GHS)',
                ], $headerStyle));
                foreach ($expenseTransactions as $t) {
                    $writer->addRow(Row::fromValuesWithStyle([
                        $dateFmt($t->transaction_date),
                        $t->member?->full_name ?? '',
                        $t->reference ?? '',
                        $t->notes ?? '',
                        (float) $t->amount,
                    ], $amountStyle));
                }
                $writer->addRow(Row::fromValues(['']));
            }

            // ── SECTION 5: MONTHLY SUMMARY ────────────────────────────
            $writer->addRow(Row::fromValuesWithStyle(['MONTHLY SUMMARY'], $sectionStyle));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValuesWithStyle([
                'Month', 'Income (GHS)', 'Expenses (GHS)', 'Net (GHS)',
            ], $headerStyle));
            foreach ($monthlyTotals as $month => $totals) {
                $monthNet = $totals['income'] - $totals['expense'];
                $netStyle = $monthNet < 0 ? $negAmountStyle : $amountStyle;
                $writer->addRow(Row::fromValuesWithStyle([
                    $month, $totals['income'], $totals['expense'], abs($monthNet),
                ], $netStyle));
            }
            $totalNetStyle = $net < 0 ? $boldNegAmountStyle : $boldAmountStyle;
            $writer->addRow(Row::fromValuesWithStyle([
                'TOTAL', $totalIncome, $totalExpense, abs($net),
            ], $totalNetStyle));
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
            ->with(['category', 'member'])
            ->orderBy('transaction_date')
            ->get();

        $groupByCategory = fn ($items) => $items
            ->groupBy(fn ($t) => $t->category?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'category' => $group->first()?->category?->name ?? 'Uncategorised',
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
            'logoPath' => $this->pdfLogoPath(),
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
