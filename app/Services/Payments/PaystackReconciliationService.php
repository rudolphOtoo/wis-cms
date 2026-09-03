<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Events\PaymentReceived;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Payment;
use App\Services\MnotifySmsService;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Offline-resilient reconciliation with Paystack's transaction ledger.
 *
 * When the church desktop PC is powered off, mobile money giving made via
 * Paystack payment links still succeeds on Paystack's cloud. This service
 * polls REST GET /transaction (filtered to completed payments since the
 * last local sync) and safely backfills the local `payments` and `ledger`
 * (transactions/finance) tables on the next boot.
 *
 * IDEMPOTENCY
 *   - Paystack's unique transaction reference is stored verbatim in
 *     `payments.reference` (a UNIQUE column), so a payment can never be
 *     recorded twice.
 *   - `Payment::createTransactionFromPayment()` is itself guarded by an
 *     existing-transaction lookup keyed on the same reference — exactly
 *     one income ledger entry per successful payment, no matter how many
 *     times the poll re-runs.
 *
 * @see https://paystack.com/docs/api/transaction/#list
 */
class PaystackReconciliationService
{
    private string $secretKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = (string) config('services.paystack.secret');
        $this->baseUrl = rtrim((string) config('services.paystack.base_url', 'https://api.paystack.co'), '/');
    }

    /**
     * The newest payment timestamp already present locally. Used as the
     * `since` cursor for the next poll (with an overlap buffer so a
     * Paystack boundary transaction is never missed).
     */
    public function getLastSyncedAt(): ?Carbon
    {
        $paidAt = Payment::query()
            ->where('status', PaymentStatus::Success)
            ->max('paid_at');

        return $paidAt ? Carbon::parse($paidAt) : null;
    }

    /**
     * Resolve the effective `since` cursor, rewinding by the configured
     * overlap window (~1h default) to absorb Paystack event-timestamp skew.
     */
    public function sinceCursor(?Carbon $since = null): ?Carbon
    {
        $since ??= $this->getLastSyncedAt();

        if ($since === null) {
            return null;
        }

        $overlap = (int) config('services.paystack.reconcile_overlap_minutes', 60);

        return $since->copy()->subMinutes(max(0, $overlap));
    }

    /**
     * Fetch completed Paystack transactions since the given timestamp.
     *
     * Paginates through GET /transaction until every page is drained.
     * Returns null on network failure / non-200 / deformed payload so the
     * caller can fail gracefully at boot instead of crashing the container.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function fetchCompletedTransactions(?Carbon $since = null): ?array
    {
        if ($this->secretKey === '') {
            Log::warning('payments:reconcile-paystack: PAYSTACK_SECRET_KEY not configured');

            return null;
        }

        $transactions = [];
        $page = 1;
        $perPage = 100;

        do {
            $query = [
                'status' => 'success',
                'perPage' => $perPage,
                'page' => $page,
            ];

            if ($since !== null) {
                $query['from'] = $since->toIso8601String();
            }

            try {
                $response = Http::withToken($this->secretKey)
                    ->connectTimeout(15)
                    ->timeout(30)
                    ->get("{$this->baseUrl}/transaction", $query);
            } catch (ConnectionException $e) {
                Log::warning('payments:reconcile-paystack: Paystack unreachable: '.$e->getMessage());

                return null;
            }

            if (! $response->successful()) {
                Log::warning('payments:reconcile-paystack: Paystack returned HTTP '.$response->status());

                return null;
            }

            $body = $response->json();

            if (! ($body['status'] ?? false) || ! is_array($body['data'] ?? null)) {
                Log::warning('payments:reconcile-paystack: unexpected Paystack payload', ['body' => $body]);

                return null;
            }

            $data = $body['data'];
            $transactions = array_merge($transactions, $data);

            $total = $body['meta']['total'] ?? null;
            $pageCount = $body['meta']['pageCount'] ?? $body['meta']['total_pages'] ?? null;

            $withinRange = count($data) >= $perPage
                || ($pageCount !== null && $page < (int) $pageCount)
                || ($total !== null && count($transactions) < (int) $total);

            $page++;
        } while ($withinRange && $page <= 500);

        return $transactions;
    }

    /**
     * Reconcile the remote Paystack ledger into local `payments` + ledger.
     *
     * Each transaction is applied inside its own DB::transaction so a single
     * malformed payload can never poison the batch.
     *
     * @return array{fetched: int, created: int, updated: int, skipped: int, ledger_created: int, failed: int}
     *
     * @throws \RuntimeException When the Paystack API is unreachable.
     */
    public function reconcile(?Carbon $since = null, bool $dispatchReceipts = true): array
    {
        $cursor = $this->sinceCursor($since);
        $transactions = $this->fetchCompletedTransactions($cursor);

        if ($transactions === null) {
            throw new \RuntimeException('Could not fetch completed Paystack transactions.');
        }

        $stats = [
            'fetched' => count($transactions),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'ledger_created' => 0,
            'failed' => 0,
        ];

        foreach ($transactions as $transaction) {
            $outcome = $this->reconcileTransaction($transaction, $dispatchReceipts);

            if (in_array($outcome, ['created', 'updated'], true)) {
                $stats[$outcome]++;
            } elseif ($outcome === 'failed') {
                $stats['failed']++;
            } else {
                $stats['skipped']++;
            }
        }

        // Every created/updated payment carries exactly one newly created
        // ledger entry (guarded by reference inside createTransactionFromPayment).
        $stats['ledger_created'] = $stats['created'] + $stats['updated'];

        return $stats;
    }

    /**
     * Apply a single Paystack transaction payload to the local database.
     *
     * Outcomes:
     *   - 'created'  — new remote payment + ledger entry inserted
     *   - 'updated'  — existing pending payment upgraded to success + ledger entry
     *   - 'skipped'  — already recorded (idempotent no-op)
     *   - 'failed'   — unmappable payload (logged, never crashes the batch)
     */
    public function reconcileTransaction(array $transaction, bool $dispatchReceipts = true): string
    {
        $reference = (string) ($transaction['reference'] ?? '');

        if ($reference === '') {
            Log::warning('payments:reconcile-paystack: transaction missing reference', ['tx' => $transaction]);

            return 'failed';
        }

        return DB::transaction(function () use ($reference, $transaction, $dispatchReceipts) {
            // Pessimistic lock: if a concurrent webhook or verify request is
            // simultaneously processing this same reference, we wait for it
            // to commit before reading the row — the second updater sees
            // status=success and short-circuits, producing exactly one
            // ledger entry.
            $payment = Payment::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            // Already recorded locally — never re-credit the ledger.
            if ($payment !== null && $payment->status === PaymentStatus::Success) {
                return 'skipped';
            }

            $fields = $this->mapToPaymentFields($transaction);

            if ($fields === null) {
                Log::warning('payments:reconcile-paystack: unmappable transaction', [
                    'reference' => $reference,
                    'tx' => $transaction,
                ]);

                return 'failed';
            }

            if ($payment === null) {
                $payment = (new Payment)->forceFill($fields);
                $payment->created_at = $payment->paid_at;
                $payment->save();

                $payment->createTransactionFromPayment();

                $this->logActivity($payment, 'Payment reconciled from Paystack (offline catch-up)');

                // Dispatch after the individual transaction commits so the
                // listener (SMS receipt) runs outside the locked row. Skipped
                // when --no-sms is passed (equivalent to the old behaviour of
                // reconciling without dispatching receipts).
                if ($dispatchReceipts) {
                    DB::afterCommit(fn () => event(new PaymentReceived($payment)));
                }

                return 'created';
            }

            // Existing payment confirmed successful by the remote poll. Trust the
            // member's MoMo details captured locally at initiation over any the
            // payload echoes back (for app-initiated payments Paystack's payload
            // only mirrors our metadata + its own customer profile).
            $fields['momo_number'] = $payment->momo_number ?? $fields['momo_number'];
            $fields['momo_network'] = $payment->momo_network ?? $fields['momo_network'];
            $fields['sms_pending'] = $fields['momo_number'] !== null;

            $payment->update($fields);
            $payment->createTransactionFromPayment();

            $this->logActivity($payment, 'Payment confirmed via Paystack reconciliation');

            if ($dispatchReceipts) {
                DB::afterCommit(fn () => event(new PaymentReceived($payment)));
            }

            return 'updated';
        });
    }

    /**
     * Map a Paystack transaction payload onto `payments` columns.
     *
     * Returns null when no active branch can be resolved for the payment.
     *
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>|null
     */
    public function mapToPaymentFields(array $transaction): ?array
    {
        $branchId = $this->resolveBranchId($transaction);

        if ($branchId === null) {
            Log::warning('payments:reconcile-paystack: no branch resolvable for transaction', [
                'reference' => $transaction['reference'] ?? null,
            ]);

            return null;
        }

        $paidAt = Carbon::parse(
            $transaction['paid_at'] ?? $transaction['created_at'] ?? now()
        );

        $metadata = is_array($transaction['metadata'] ?? null)
            ? $transaction['metadata']
            : [];

        $amountMinor = (int) ($transaction['amount'] ?? 0);
        $amount = $amountMinor > 0 ? round($amountMinor / 100, 2) : 0.0;
        $momoNumber = $this->resolveMomoNumber($transaction, $metadata);

        return [
            'branch_id' => $branchId,
            'member_id' => $this->resolveMemberId($metadata),
            'payment_type' => $this->resolvePaymentType($metadata),
            'amount' => $amount,
            'currency' => (string) ($transaction['currency'] ?? 'GHS'),
            'channel' => $this->resolveChannel($transaction['channel'] ?? ''),
            'momo_network' => $this->resolveMomoNetwork($transaction, $metadata),
            'momo_number' => $momoNumber,
            'status' => PaymentStatus::Success,
            'sync_status' => Payment::SYNC_STATUS_SYNCED_FROM_REMOTE,
            'sms_pending' => $momoNumber !== null,
            'reference' => (string) $transaction['reference'],
            'gateway_reference' => (string) ($transaction['id'] ?? $transaction['reference']),
            'metadata' => $metadata,
            'gateway_response' => $transaction,
            'paid_at' => $paidAt,
            'updated_at' => now(),
        ];
    }

    /**
     * Dispatch the queued receipt SMS for every reconciled payment.
     *
     * Only succeeds when mNotify accepts the send (or dry-run is on); a
     * transient failure leaves the payment flagged sms_pending so the next
     * boot / scheduled poll retries it.
     *
     * @return array{sent: int, failed: int, pending: int}
     */
    public function sendPendingReceipts(): array
    {
        $pending = Payment::query()
            ->where('status', PaymentStatus::Success)
            ->where('sms_pending', true)
            ->whereNotNull('momo_number')
            ->get();

        $stats = ['sent' => 0, 'failed' => 0, 'pending' => $pending->count()];
        $sms = app(MnotifySmsService::class);

        foreach ($pending as $payment) {
            try {
                $sent = $sms->sendReceipt($payment);
            } catch (\Throwable $e) {
                Log::warning('payments:reconcile-paystack: receipt SMS failed (transient)', [
                    'payment_id' => $payment->id,
                    'reference' => $payment->reference,
                    'error' => $e->getMessage(),
                ]);
                $sent = false;
            }

            if ($sent) {
                $payment->update([
                    'sms_pending' => false,
                    'receipt_sms_sent_at' => now(),
                ]);
                $stats['sent']++;
            } else {
                $stats['failed']++;
            }
        }

        $stats['pending'] = max(0, $stats['pending'] - $stats['sent']);

        return $stats;
    }

    // ─── Field resolution helpers ─────────────────────────────────

    private function resolveBranchId(array $transaction): ?string
    {
        $metadata = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : [];

        if (($metadata['branch_id'] ?? null) !== null
            && Branch::query()->whereKey($metadata['branch_id'])->exists()
        ) {
            return (string) $metadata['branch_id'];
        }

        return Branch::query()->where('is_active', true)->first()?->id;
    }

    private function resolveMemberId(array $metadata): ?string
    {
        $memberId = $metadata['member_id'] ?? null;

        if ($memberId !== null && Member::query()->whereKey($memberId)->exists()) {
            return (string) $memberId;
        }

        return null;
    }

    private function resolvePaymentType(array $metadata): string
    {
        $type = $metadata['payment_type'] ?? null;

        if ($type !== null && PaymentType::tryFrom((string) $type) !== null) {
            return (string) $type;
        }

        return PaymentType::Offering->value;
    }

    private function resolveChannel(string $channel): string
    {
        return match (strtolower($channel)) {
            'mobile_money', 'momo', 'ussd' => PaymentChannel::MobileMoney->value,
            'bank', 'bank_transfer', 'transfer' => PaymentChannel::BankTransfer->value,
            default => PaymentChannel::Card->value,
        };
    }

    private function resolveMomoNetwork(array $transaction, array $metadata): ?string
    {
        $provider = $metadata['momo_network'] ?? null;

        if ($provider === null) {
            $authorization = is_array($transaction['authorization'] ?? null) ? $transaction['authorization'] : [];
            $provider = $authorization['mobile_money']['provider'] ?? $authorization['provider'] ?? null;
        }

        return match (strtolower((string) $provider)) {
            'mtn' => 'mtn',
            'telecel', 'vod', 'vodafone' => 'telecel',
            'at', 'airteltigo', 'atl' => 'at',
            default => $provider !== null ? strtolower((string) $provider) : null,
        };
    }

    private function resolveMomoNumber(array $transaction, array $metadata): ?string
    {
        $phone = $metadata['phone']
            ?? $metadata['momo_number']
            ?? $metadata['phonenumber']
            ?? null;

        if ($phone === null) {
            $customer = is_array($transaction['customer'] ?? null) ? $transaction['customer'] : [];
            $phone = $customer['phone'] ?? null;
        }

        if ($phone === null) {
            $authorization = is_array($transaction['authorization'] ?? null) ? $transaction['authorization'] : [];
            $phone = $authorization['mobile_money']['phone']
                ?? $authorization['mobile_money']['number']
                ?? null;
        }

        if ($phone === null || trim((string) $phone) === '') {
            return null;
        }

        $normalized = PhoneNormalizer::normalize((string) $phone);

        return preg_match('/^0[0-9]{9}$/', $normalized) === 1 ? $normalized : null;
    }

    private function logActivity(Payment $payment, string $description): void
    {
        try {
            activity()->performedOn($payment)->log($description);
        } catch (\Throwable $e) {
            Log::warning('payments:reconcile-paystack: activity log failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
