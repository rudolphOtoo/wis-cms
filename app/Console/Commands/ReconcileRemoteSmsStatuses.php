<?php

namespace App\Console\Commands;

use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile local delivery statuses with mNotify's remote state.
 *
 * After the system comes back online, this command queries mNotify to
 * determine the actual delivery status of past-due messages.
 *
 * PHASE 2 — False-positive prevention:
 *   Missing post-schedule jobs are NO LONGER assumed to be dispatched.
 *   The command now cross-references mNotify delivery reports and
 *   checks the current balance before marking a missing job:
 *     - Found in delivery reports       → mark as per reported status
 *     - Missing, balance = 0 or null    → failed_provider (UNCONFIRMED_POSSIBLE_CREDIT_DEPLETION)
 *     - Missing, balance > 0            → dispatched (ASSUMED_DISPATCHED with warning)
 *
 * Status mapping (when found in schedule or reports):
 *   sent/delivered          → dispatched
 *   failed + no balance     → failed_provider (INSUFFICIENT_BALANCE)
 *   failed + other reason   → failed_provider (with exact reason)
 *   still scheduled         → stays scheduled_remote (no change)
 *   purged (past-schedule)  → dispatched or failed_provider (balance heuristic)
 *
 * Usage: php artisan sms:reconcile-remote-statuses
 */
class ReconcileRemoteSmsStatuses extends Command
{
    protected $signature = 'sms:reconcile-remote-statuses
                            {--force : Reconcile even when APP_ENV is local or testing}';

    protected $description = 'Reconcile local SMS delivery statuses with mNotify remote report';

    /**
     * Atomic lock key — prevents concurrent runs when entrypoint.sh
     * and the task scheduler both trigger this command simultaneously.
     * Lock held for 60s (reconciliation may be slow with large batches);
     * acquire waits up to 5s.
     */
    private const LOCK_KEY = 'sms_reconcile_remote_statuses_lock';

    private const LOCK_TTL = 60;

    private const LOCK_WAIT = 5;

    public function handle(): int
    {
        // ─── Atomic execution lock ───────────────────────────────
        // Prevents race conditions when container boot (entrypoint.sh)
        // and the hourly/daily cron fire simultaneously.
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        try {
            $lock->block(self::LOCK_WAIT);
        } catch (LockTimeoutException) {
            $this->warn('Another sms:reconcile-remote-statuses is already running. Skipping.');
            Log::info('sms:reconcile-remote-statuses: skipped — concurrent execution detected');

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
        $dryRunSetting = config('services.mnotify.dry_run');
        $liveSmsConfigured = $dryRunSetting !== null
            && filter_var($dryRunSetting, FILTER_VALIDATE_BOOLEAN) === false;

        if (app()->environment('local', 'testing') && ! $liveSmsConfigured && ! $this->option('force')) {
            $this->info('Skipping: APP_ENV is local/testing without MNOTIFY_DRY_RUN=false. Use --force to override.');

            return self::SUCCESS;
        }

        if (! config('services.mnotify.api_key')) {
            $this->error('mNotify API key not configured.');

            return self::FAILURE;
        }

        $this->line('Reconciling remote SMS delivery statuses...');

        // ── Phase 2: Fetch all data sources upfront ──
        $remoteJobs = $this->fetchRemoteSchedule();
        if ($remoteJobs === null) {
            $this->error('Could not retrieve mNotify schedule. Aborting.');
            Log::warning('sms:reconcile-remote-statuses: could not fetch remote schedule');

            return self::FAILURE;
        }

        $remoteDeliveryReports = $this->fetchDeliveryReports();
        $currentBalance = $this->checkCurrentBalance();

        $this->info('  Fetched '.count($remoteJobs).' scheduled jobs, '
            .count($remoteDeliveryReports).' delivery reports.');

        if ($currentBalance !== null) {
            $this->info('  Current mNotify balance: GH₵ '.number_format($currentBalance, 2));
        } else {
            $this->warn('  Could not retrieve mNotify balance.');
        }

        // Build lookup: map remote jobs by message body + date_time
        $remoteIndex = $this->buildRemoteIndex($remoteJobs);

        // Find all past-due local deliveries that need reconciliation
        $deliveries = ScheduledSmsDelivery::query()
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
                ScheduledSmsDelivery::STATUS_CANCELLED,
            ])
            ->where('scheduled_at', '<', now())
            ->get();

        $this->line('  Found '.count($deliveries).' past-due delivery(ies) to reconcile.');

        $stats = [
            'dispatched' => 0,
            'dispatched_assumed' => 0,
            'failed_provider' => 0,
            'unchanged' => 0,
        ];

        foreach ($deliveries as $delivery) {
            $result = $this->reconcileDelivery($delivery, $remoteIndex, $remoteDeliveryReports, $currentBalance);
            $stats[$result]++;
        }

        $summaryParts = [
            "{$stats['dispatched']} dispatched (confirmed)",
            "{$stats['dispatched_assumed']} dispatched (assumed/unconfirmed)",
            "{$stats['failed_provider']} failed (provider)",
            "{$stats['unchanged']} unchanged",
        ];
        $this->info('Reconciliation complete: '.implode(', ', $summaryParts));

        Log::info('sms:reconcile-remote-statuses completed', $stats);

        return self::SUCCESS;
    }

    // ─── Data Fetching ─────────────────────────────────────────

    /**
     * Fetch the full remote schedule from mNotify's API.
     *
     * Returns the array of scheduled jobs, or null on failure.
     */
    protected function fetchRemoteSchedule(): ?array
    {
        $apiKey = config('services.mnotify.api_key');
        $endpoint = rtrim(config('services.mnotify.base_url'), '/')."/scheduled?key={$apiKey}";

        try {
            $response = Http::retry(3, 200, null, false)->connectTimeout(5)->timeout(10)->get($endpoint);
        } catch (ConnectionException $e) {
            Log::warning('mNotify remote schedule fetch failed: '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('mNotify remote schedule fetch returned HTTP '.$response->status());

            return null;
        }

        $jobs = $response->json('summary');

        return is_array($jobs) ? array_values($jobs) : [];
    }

    /**
     * Fetch delivery reports from mNotify.
     *
     * Returns a flat array of campaign records keyed by _id.
     * Fail-open: returns [] on network/decode failure.
     */
    protected function fetchDeliveryReports(): array
    {
        $apiKey = config('services.mnotify.api_key');
        if (! $apiKey) {
            return [];
        }

        $endpoint = rtrim(config('services.mnotify.base_url'), '/')."/reports/campaigns?key={$apiKey}";

        try {
            $response = Http::retry(3, 200, null, false)->connectTimeout(5)->timeout(10)->get($endpoint);
        } catch (\Throwable $e) {
            Log::warning('mNotify delivery reports fetch failed: '.$e->getMessage());

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();

        $reports = $payload['summary'] ?? (is_array($payload) ? $payload : []);

        // Index reports by _id for O(1) lookup
        $indexed = [];
        foreach ($reports as $report) {
            $id = (string) ($report['_id'] ?? '');
            if ($id !== '') {
                $indexed[$id] = $report;
            }
        }

        return $indexed;
    }

    /**
     * Check the current mNotify balance.
     *
     * Returns null if the balance API is unreachable (fail-open).
     */
    protected function checkCurrentBalance(): ?float
    {
        try {
            return app(MnotifySmsService::class)->checkBalance();
        } catch (\Throwable) {
            return null;
        }
    }

    // ─── Index Building ────────────────────────────────────────

    /**
     * Build a lookup index from remote jobs, keyed by normalized
     * message body + date_time for O(1) matching.
     */
    protected function buildRemoteIndex(array $remoteJobs): array
    {
        $index = [];

        foreach ($remoteJobs as $job) {
            $message = trim((string) ($job['message'] ?? ''));
            $dateTime = $job['date_time'] ?? '';
            $status = $job['status'] ?? null;
            $id = (string) ($job['_id'] ?? '');

            if ($message === '' || $dateTime === '') {
                continue;
            }

            $key = $this->makeIndexKey($message, $dateTime);
            $index[$key] = [
                'remote_id' => $id,
                'status' => $status,
                'raw' => $job,
            ];
        }

        return $index;
    }

    /**
     * Build a deterministic lookup key from message body + datetime.
     */
    protected function makeIndexKey(string $message, string $dateTime): string
    {
        return md5($dateTime.'|'.$message);
    }

    // ─── Reconciliation ────────────────────────────────────────

    /**
     * Reconcile a single local delivery against the remote state.
     *
     * Phase 2: Cross-references delivery reports and checks balance
     * before assuming a missing job was dispatched.
     *
     * Returns the stats key that was incremented.
     */
    protected function reconcileDelivery(
        ScheduledSmsDelivery $delivery,
        array $remoteIndex,
        array $remoteDeliveryReports,
        ?float $currentBalance,
    ): string {
        $dateTime = $delivery->scheduled_at->format('Y-m-d H:i:s');
        $key = $this->makeIndexKey(trim($delivery->message_body), $dateTime);

        $remote = $remoteIndex[$key] ?? null;

        if ($remote !== null) {
            // ── Found in mNotify's active schedule ──
            return $this->reconcileFromSchedule($delivery, $remote);
        }

        // ── NOT found in schedule — cross-reference delivery reports ──
        $reportMatch = $this->findDeliveryReportMatch($delivery, $remoteDeliveryReports);

        if ($reportMatch !== null) {
            return $this->reconcileFromDeliveryReport($delivery, $reportMatch);
        }

        // ── PHASE 2: Missing everywhere — false-positive prevention ──
        if ($delivery->scheduled_at->isPast()) {
            return $this->reconcileMissingJob($delivery, $currentBalance);
        }

        // Future job not found — keep as is (may not have been pushed yet)
        return 'unchanged';
    }

    /**
     * Reconcile using data from mNotify's active schedule.
     */
    private function reconcileFromSchedule(
        ScheduledSmsDelivery $delivery,
        array $remote,
    ): string {
        $remoteStatus = strtolower((string) ($remote['status'] ?? ''));

        return match (true) {
            // Successfully sent or delivered
            in_array($remoteStatus, ['sent', 'delivered', 'success', 'completed']) => $this->markDispatchedConfirmed($delivery, $remote['raw'] ?? null),

            // Still queued on mNotify (hasn't fired yet)
            in_array($remoteStatus, ['scheduled', 'pending', 'queued']) => 'unchanged',

            // Failed with insufficient balance
            str_contains($remoteStatus, 'balance')
                || str_contains($remoteStatus, 'credit')
                || ($remoteStatus === 'failed' && str_contains(
                    strtolower($remote['raw']['message_detail'] ?? $remote['raw']['message'] ?? $remote['raw']['reason'] ?? ''),
                    'balance'
                )) => $this->markFailedProviderRecord($delivery, 'INSUFFICIENT_BALANCE', $remote['raw']),

            // Failed for other provider reasons
            in_array($remoteStatus, ['failed', 'rejected', 'error', 'invalid']) => $this->markFailedProviderRecord(
                $delivery,
                strtoupper($remoteStatus).': '.($remote['raw']['message_detail'] ?? $remote['raw']['message'] ?? $remote['raw']['reason'] ?? 'Provider rejected'),
                $remote['raw'],
            ),

            // Default: if past scheduled_at and status is ambiguous, assume dispatched
            $delivery->scheduled_at->isPast() => $this->markDispatchedConfirmed($delivery, $remote['raw'] ?? null),

            // Unknown status for future job — leave unchanged
            default => 'unchanged',
        };
    }

    /**
     * Reconcile using data from mNotify delivery reports.
     *
     * This is the authoritative source for messages that have already
     * been processed by mNotify but may have been purged from the schedule.
     */
    private function reconcileFromDeliveryReport(
        ScheduledSmsDelivery $delivery,
        array $report,
    ): string {
        $reportedStatus = strtolower((string) ($report['status'] ?? ''));

        return match (true) {
            in_array($reportedStatus, ['sent', 'delivered', 'success', 'completed']) => $this->markDispatchedConfirmed($delivery, $report),

            str_contains($reportedStatus, 'balance')
                || str_contains($reportedStatus, 'credit')
                || ($reportedStatus === 'failed' && str_contains(
                    strtolower($report['message_detail'] ?? $report['message'] ?? $report['reason'] ?? ''),
                    'balance'
                )) => $this->markFailedProviderRecord($delivery, 'INSUFFICIENT_BALANCE', (array) $report),

            in_array($reportedStatus, ['failed', 'rejected', 'error', 'invalid']) => $this->markFailedProviderRecord(
                $delivery,
                strtoupper($reportedStatus).': '.($report['message_detail'] ?? $report['message'] ?? $report['reason'] ?? 'Provider rejected'),
                (array) $report,
            ),

            in_array($reportedStatus, ['scheduled', 'pending', 'queued']) => 'unchanged',

            default => 'unchanged',
        };
    }

    /**
     * PHASE 2: Handle a missing post-schedule job using the balance heuristic.
     *
     * When a job is past its scheduled_at but missing from both mNotify's
     * active schedule AND delivery reports, do NOT assume it was dispatched.
     *
     * Instead, check the current balance as a heuristic:
     *   - Balance = 0 or null  → failed_provider (UNCONFIRMED_POSSIBLE_CREDIT_DEPLETION)
     *   - Balance > 0           → dispatched (ASSUMED_DISPATCHED with warning)
     */
    private function reconcileMissingJob(
        ScheduledSmsDelivery $delivery,
        ?float $currentBalance,
    ): string {
        $balanceDepleted = ($currentBalance === null || $currentBalance <= 0);

        if ($balanceDepleted) {
            $reason = $currentBalance === null
                ? 'UNCONFIRMED_POSSIBLE_CREDIT_DEPLETION (balance API unreachable)'
                : 'UNCONFIRMED_POSSIBLE_CREDIT_DEPLETION (balance zero, GH₵ 0.00)';

            $this->markFailedProviderRecord($delivery, $reason);

            $this->warn("  ⚠ [{$delivery->id}] {$delivery->status} → failed_provider ({$reason})");

            return 'failed_provider';
        }

        // Balance is positive — mark as dispatched with a warning (conservative)
        $delivery->markDispatched();
        $delivery->update([
            'error_message' => trim(($delivery->error_message ? $delivery->error_message.' | ' : '')
                .'ASSUMED_DISPATCHED: Job missing from mNotify but balance positive (GH₵ '.number_format($currentBalance, 2).')'),
        ]);

        $this->warn("  ⚠ [{$delivery->id}] {$delivery->status} → dispatched (UNCONFIRMED — balance positive, GH₵ ".number_format($currentBalance, 2).')');

        return 'dispatched_assumed';
    }

    // ─── Delivery Report Matching ───────────────────────────────

    /**
     * Match a local delivery against mNotify delivery reports.
     *
     * Tries _id match first, then falls back to phone + message + time window.
     */
    private function findDeliveryReportMatch(
        ScheduledSmsDelivery $delivery,
        array $remoteDeliveryReports,
    ): ?array {
        // Try _id match
        if ($delivery->mnotify_job_id && isset($remoteDeliveryReports[$delivery->mnotify_job_id])) {
            return $remoteDeliveryReports[$delivery->mnotify_job_id];
        }

        // Fuzzy match: phone + message + time window (±2 hours)
        $windowStart = $delivery->scheduled_at->copy()->subHours(2);
        $windowEnd = $delivery->scheduled_at->copy()->addHours(2);
        $phone = preg_replace('/[^0-9+]/', '', $delivery->phone);
        $body = $delivery->message_body;

        foreach ($remoteDeliveryReports as $report) {
            $reportPhone = preg_replace('/[^0-9+]/', '', (string) ($report['phone'] ?? ''));
            if ($reportPhone !== $phone) {
                continue;
            }

            $reportTime = isset($report['date_time'])
                ? Carbon::parse($report['date_time'])
                : null;
            if (! $reportTime || ! $reportTime->between($windowStart, $windowEnd)) {
                continue;
            }

            // Match by message body (first 30 chars) or accept phone + time match
            $reportMsg = (string) ($report['message'] ?? '');
            if ($reportMsg !== ''
                && mb_strpos($reportMsg, mb_substr($body, 0, min(30, mb_strlen($body)))) !== false
            ) {
                return $report;
            }

            // Fallback: exact phone + time match (bodies may be truncated by mNotify)
            return $report;
        }

        return null;
    }

    // ─── Marking Helpers ───────────────────────────────────────

    protected function markDispatchedConfirmed(ScheduledSmsDelivery $delivery, ?array $rawResponse = null): string
    {
        $delivery->markDispatched($rawResponse);

        return 'dispatched';
    }

    protected function markFailedProviderRecord(
        ScheduledSmsDelivery $delivery,
        string $reason,
        ?array $remoteRaw = null,
    ): string {
        $delivery->markFailedProvider($reason, $remoteRaw);

        Log::error("mNotify reconciliation: delivery #{$delivery->id} failed (provider)", [
            'phone' => $delivery->phone,
            'source_type' => $delivery->source_type,
            'failure_reason' => $reason,
        ]);

        return 'failed_provider';
    }
}
