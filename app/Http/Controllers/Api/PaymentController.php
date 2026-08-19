<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitializePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Branch;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gatewayManager,
    ) {}

    /**
     * GET /api/payments/history
     *
     * Paginated, branch-scoped list of online payments with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->with(['member', 'recorder']);

        if ($type = $request->input('payment_type')) {
            $query->where('payment_type', $type);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($memberId = $request->input('member_id')) {
            $query->where('member_id', $memberId);
        }

        $payments = $query
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => PaymentResource::collection($payments->items()),
            'meta' => [
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/payments/initialize
     *
     * Creates a pending payment record and invokes the gateway driver.
     * Returns the reference + display_text so the frontend can show
     * the MoMo prompt / approval screen.
     */
    public function store(InitializePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        // Generate a unique reference.
        $reference = 'WIS-'.now()->timestamp.'-'.strtoupper(bin2hex(random_bytes(4)));

        // Normalize phone number.
        $momoNumber = $validated['momo_number']
            ? PhoneNormalizer::normalize($validated['momo_number'])
            : null;

        // Resolve branch: authenticated user's branch, or first active branch
        // for the public /give page.
        $branchId = $user?->branch_id ?? Branch::where('is_active', true)->first()?->id;

        // Create the pending payment record.
        $payment = Payment::create([
            'branch_id' => $branchId,
            'member_id' => $validated['member_id'] ?? null,
            'payment_type' => $validated['payment_type'],
            'amount' => $validated['amount'],
            'currency' => $validated['currency'] ?? 'GHS',
            'channel' => $validated['channel'],
            'momo_network' => $validated['momo_network'] ?? null,
            'momo_number' => $momoNumber,
            'status' => PaymentStatus::Pending,
            'reference' => $reference,
            'metadata' => [
                'notes' => $validated['notes'] ?? null,
                'initiated_by' => $user?->id,
                'member_id' => $validated['member_id'] ?? null,
            ],
            'recorded_by_user_id' => $user?->id,
        ]);

        // Invoke the gateway driver.
        $driver = $this->gatewayManager->driver();

        try {
            $email = $validated['email']
                ?? $user?->email
                ?? "giving-{$reference}@wis-cms.local";

            $gatewayResponse = $driver->initializePayment([
                'email' => $email,
                'amount' => (float) $validated['amount'],
                'currency' => $validated['currency'] ?? 'GHS',
                'reference' => $reference,
                'channel' => $validated['channel'],
                'momo_network' => $validated['momo_network'] ?? null,
                'momo_number' => $momoNumber,
                'metadata' => [
                    'payment_id' => $payment->id,
                    'payment_type' => $validated['payment_type'],
                    'branch_id' => $branchId,
                ],
            ]);

            $payment->update([
                'gateway_reference' => $gatewayResponse['reference'] ?? null,
            ]);

            activity()->causedBy($user)->performedOn($payment)
                ->log("Initialized {$validated['payment_type']} payment of GHS ".number_format((float) $validated['amount'], 2));

            return response()->json([
                'data' => [
                    'reference' => $reference,
                    'display_text' => $gatewayResponse['display_text'] ?? '',
                    'status' => $gatewayResponse['status'] ?? 'pending',
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Payment initialization failed', [
                'payment_id' => $payment->id,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            $payment->update(['status' => PaymentStatus::Failed]);

            return response()->json([
                'message' => 'Payment initialization failed. Please try again.',
            ], 422);
        }
    }

    /**
     * GET /api/payments/verify/{reference}
     *
     * Polls the gateway for transaction status. Used by the frontend
     * while the user approves the MoMo prompt on their phone.
     */
    public function verify(string $reference): JsonResponse
    {
        $payment = Payment::where('reference', $reference)->firstOrFail();

        // If already terminal, return current status without calling gateway.
        if ($payment->status->value !== PaymentStatus::Pending->value) {
            return response()->json([
                'data' => new PaymentResource($payment->load(['member', 'recorder'])),
            ]);
        }

        $driver = $this->gatewayManager->driver();

        try {
            $result = $driver->verifyTransaction($reference);

            DB::transaction(function () use ($payment, $result) {
                // Idempotency: skip if already succeeded.
                if ($payment->status === PaymentStatus::Success) {
                    return;
                }

                $status = PaymentStatus::tryFrom($result['status']) ?? PaymentStatus::Failed;

                $update = [
                    'status' => $status,
                    'gateway_response' => $result['gateway_response'],
                ];

                if ($status === PaymentStatus::Success) {
                    $update['paid_at'] = $result['paid_at'] ?? now();
                }

                $payment->update($update);

                // Create the corresponding Transaction in the finance ledger.
                if ($status === PaymentStatus::Success) {
                    $payment->createTransactionFromPayment();
                }
            });

            $payment->refresh();

            return response()->json([
                'data' => new PaymentResource($payment->load(['member', 'recorder'])),
            ]);
        } catch (\Throwable $e) {
            Log::error('Payment verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'data' => new PaymentResource($payment->load(['member', 'recorder'])),
            ]);
        }
    }

    /**
     * GET /api/payments/stats
     *
     * Summary totals for the admin payments dashboard.
     */
    public function stats(): JsonResponse
    {
        $now = now();

        $thisMonth = Payment::query()
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->selectRaw("
                COUNT(*) AS total_count,
                COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) AS total_received,
                COUNT(*) FILTER (WHERE status = 'success') AS success_count,
                COUNT(*) FILTER (WHERE status = 'pending') AS pending_count,
                COUNT(*) FILTER (WHERE status = 'failed') AS failed_count
            ")
            ->first();

        $byType = Payment::query()
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->where('status', PaymentStatus::Success)
            ->selectRaw('payment_type, SUM(amount) AS total, COUNT(*) AS count')
            ->groupBy('payment_type')
            ->get()
            ->keyBy('payment_type');

        return response()->json([
            'data' => [
                'this_month' => [
                    'total_count' => (int) ($thisMonth->total_count ?? 0),
                    'total_received' => (float) ($thisMonth->total_received ?? 0),
                    'success_count' => (int) ($thisMonth->success_count ?? 0),
                    'pending_count' => (int) ($thisMonth->pending_count ?? 0),
                    'failed_count' => (int) ($thisMonth->failed_count ?? 0),
                ],
                'by_type' => $byType,
            ],
        ]);
    }
}
