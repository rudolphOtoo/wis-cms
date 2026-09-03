<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a payment achieves successful status — via webhook, user-initiated
 * verify, cold-boot reconciliation, or manual cash entry.
 *
 * Accepts either a Payment model (online payment) or a Transaction model
 * (manual cash entry). Listeners must handle both types.
 *
 * Listeners (e.g. SendPaymentReceiptSms) run synchronously after the current
 * request commits. Transient failures should be caught and logged; the payment
 * remains flagged sms_pending for the next reconciliation cycle to retry.
 */
class PaymentReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public Payment|Transaction $model) {}
}
