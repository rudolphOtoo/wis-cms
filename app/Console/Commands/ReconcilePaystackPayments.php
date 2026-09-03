<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\PaystackReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cold-boot catch-up for mobile money giving made while the church PC
 * was powered off.
 *
 * 1. Reads the newest local payment timestamp (PaystackReconciliationService
 *    computes the `since` cursor with an overlap buffer).
 * 2. Polls Paystack GET /transaction for every completed transaction since
 *    then — every record creation runs inside a DB::transaction, inserts
 *    missing payment intents, generates the income ledger entries (Tithe,
 *    Offering, Building Fund, …) and marks the record synced_from_remote.
 * 3. Flushes any payments flagged sms_pending by dispatching their receipt
 *    SMS through mNotify (dry-run safe).
 *
 * Wired into docker/entrypoint.sh so reconciliation (and receipt SMS)
 * automatically runs on every PC boot. Also scheduled in routes/console.php.
 *
 * Usage: php artisan payments:reconcile-paystack
 */
class ReconcilePaystackPayments extends Command
{
    protected $signature = 'payments:reconcile-paystack
                            {--since= : Override the "since" cursor (ISO8601 or Y-m-d H:i:s)}
                            {--no-sms : Reconcile without dispatching pending receipt SMS}';

    protected $description = 'Reconcile offline mobile money payments from Paystack and flush pending receipt SMS';

    private const LOCK_KEY = 'payments_reconcile_paystack_lock';

    private const LOCK_TTL = 120;

    private const LOCK_WAIT = 5;

    public function handle(): int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        try {
            $lock->block(self::LOCK_WAIT);
        } catch (LockTimeoutException) {
            $this->warn('Another payments:reconcile-paystack is already running. Skipping.');
            Log::info('payments:reconcile-paystack: skipped — concurrent execution detected');

            return self::SUCCESS;
        }

        try {
            return $this->runReconcile();
        } finally {
            $lock->release();
        }
    }

    protected function runReconcile(): int
    {
        if (! config('services.paystack.secret')) {
            $this->error('Paystack secret key not configured. Set PAYSTACK_SECRET_KEY.');

            return self::FAILURE;
        }

        $service = app(PaystackReconciliationService::class);

        $since = $this->option('since')
            ? Carbon::parse((string) $this->option('since'))
            : null;

        $this->info('Reconciling completed Paystack transactions...');

        try {
            $stats = $service->reconcile($since, $this->option('no-sms') ? false : true);
        } catch (\Throwable $e) {
            $this->error('Could not reach Paystack: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reconciled %d transaction(s): %d created, %d updated, %d skipped, %d failed (ledger entries created: %d).',
            $stats['fetched'],
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
            $stats['failed'],
            $stats['ledger_created'],
        ));

        if ($this->option('no-sms')) {
            $this->warn('--no-sms: skipping pending receipt SMS dispatch.');
        } else {
            $smsStats = $service->sendPendingReceipts();
            $this->info(sprintf(
                'Receipt SMS: %d sent, %d failed (left pending for next run), %d skipped (no phone on file).',
                $smsStats['sent'],
                $smsStats['failed'],
                $smsStats['pending'],
            ));
        }

        return self::SUCCESS;
    }
}
