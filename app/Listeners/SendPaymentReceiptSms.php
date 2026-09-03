<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\PaymentStatus;
use App\Events\PaymentReceived;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\MnotifySmsService;
use Illuminate\Support\Facades\Log;

/**
 * Dispatch a receipt SMS via mNotify whenever a payment achieves success.
 *
 * Handles both Payment models (online payments via Paystack) and Transaction
 * models (manual cash entries). For manual entries the phone number is
 * resolved from the linked member record.
 *
 * Runs synchronously after the request commits. Transient failures (network
 * timeouts, mNotify 5xx) are caught and logged; the payment remains flagged
 * sms_pending so the next reconciliation cycle retries.
 */
class SendPaymentReceiptSms
{
    public function __construct(
        private readonly MnotifySmsService $sms,
    ) {}

    public function handle(PaymentReceived $event): void
    {
        $model = $event->model;

        if ($model instanceof Transaction) {
            $this->handleManualEntry($model);

            return;
        }

        $this->handleOnlinePayment($model);
    }

    private function handleOnlinePayment(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Success || ! $payment->momo_number) {
            return;
        }

        try {
            $sent = $this->sms->sendReceipt($payment);
        } catch (\Throwable $e) {
            Log::warning('Payment receipt SMS failed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($sent) {
            $payment->update([
                'sms_pending' => false,
                'receipt_sms_sent_at' => now(),
            ]);

            Log::info('Payment receipt SMS sent', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'momo_number' => $payment->momo_number,
            ]);
        }
    }

    private function handleManualEntry(Transaction $transaction): void
    {
        if ($transaction->type !== 'income') {
            return;
        }

        $member = $transaction->member;

        if ($member === null || ! $member->phone) {
            return;
        }

        $amount = number_format((float) $transaction->amount, 2);
        $date = $transaction->transaction_date?->format('d M Y H:i') ?? now()->format('d M Y H:i');
        $receiptNumber = $transaction->receipt_number ?? $transaction->reference ?? '';
        $categoryName = $transaction->category?->name ?? 'offering';

        $message = "Thank you! We received your {$categoryName} of GHS {$amount} on {$date}. Ref: {$receiptNumber}.";

        try {
            $sent = $this->sms->send($member->phone, $message);
        } catch (\Throwable $e) {
            Log::warning('Manual entry receipt SMS failed', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($sent) {
            Log::info('Manual entry receipt SMS sent', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'phone' => $member->phone,
            ]);
        }
    }
}
