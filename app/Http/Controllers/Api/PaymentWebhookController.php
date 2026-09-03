<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Events\PaymentReceived;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook endpoint for Paystack payment notifications.
 *
 * AUTH MODEL
 *   - No Sanctum (caller is Paystack servers, not a logged-in user).
 *   - HMAC SHA512 signature verified via X-Paystack-Signature header
 *     using the PAYSTACK_WEBHOOK_SECRET from config.
 *
 * IDEMPOTENCY
 *   - Duplicate charge.success events are silently ignored. The Payment
 *     record's status is checked before any update; a second delivery
 *     returns 200 without double-processing.
 *
 * ATOMICITY
 *   - Status update + Transaction creation are wrapped in DB::transaction
 *     so the ledger and payment record are always consistent.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gatewayManager,
    ) {}

    /**
     * POST /api/webhooks/payments/paystack
     *
     * Expected headers:
     *   X-Paystack-Signature: HMAC SHA512 of request body
     *
     * Paystack sends charge.success events when a payment completes.
     * We return 200 immediately to prevent retries, then process
     * the event asynchronously-safe within a DB transaction.
     */
    public function handle(Request $request): JsonResponse
    {
        $driver = $this->gatewayManager->driver();

        try {
            $event = $driver->handleWebhook([
                'body' => $request->getContent(),
                'signature' => $request->header('X-Paystack-Signature', ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Payment webhook: invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature'], 403);
        } catch (\Throwable $e) {
            Log::error('Payment webhook: parse error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Webhook error'], 400);
        }

        // Only process charge.success events.
        if ($event['event'] !== 'charge.success') {
            return response()->json(['message' => 'Event ignored']);
        }

        $reference = $event['reference'];

        if (empty($reference)) {
            return response()->json(['message' => 'Missing reference']);
        }

        // Atomically update the payment and create the corresponding ledger
        // Transaction. The payment row is pessimistically locked (lockForUpdate())
        // inside the transaction so two simultaneous webhooks (or a webhook racing
        // the verify endpoint / reconciliation poll) for the same reference are
        // serialized — the second waiter re-reads status=success and short-circuits,
        // guaranteeing exactly one ledger entry.
        /** @var Payment|null $payment */
        $payment = null;

        $processed = DB::transaction(function () use ($reference, $event, &$payment) {
            $payment = Payment::where('reference', $reference)->lockForUpdate()->first();

            if ($payment === null) {
                Log::warning('Payment webhook: payment not found', [
                    'reference' => $reference,
                ]);

                return false;
            }

            // Idempotency: already processed — return 200 without double-creating.
            if ($payment->status === PaymentStatus::Success) {
                Log::info('Payment webhook: duplicate delivery suppressed', [
                    'payment_id' => $payment->id,
                    'reference' => $reference,
                ]);

                return false;
            }

            $paidAt = $event['data']['paid_at'] ?? now();

            $payment->update([
                'status' => PaymentStatus::Success,
                'gateway_reference' => $event['data']['id'] ?? $payment->gateway_reference,
                'gateway_response' => $event['data'],
                'paid_at' => $paidAt,
                // Flag for the receipt-SMS flush. When the cloud relay is
                // unreachable the next reconcile run delivers the receipt.
                'sms_pending' => $payment->momo_number !== null,
            ]);

            $payment->createTransactionFromPayment();

            return true;
        });

        if (! $processed) {
            return response()->json(['message' => 'Already processed']);
        }

        event(new PaymentReceived($payment));

        activity()
            ->performedOn($payment)
            ->log("Payment {$payment->reference} confirmed via webhook — GHS ".number_format((float) $payment->amount, 2));

        Log::info('Payment webhook: processed successfully', [
            'payment_id' => $payment->id,
            'reference' => $reference,
            'amount' => $payment->amount,
        ]);

        return response()->json(['message' => 'Processed']);
    }
}
