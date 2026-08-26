<?php

namespace App\Console\Commands;

use App\Models\ScheduledSmsDelivery;
use App\Models\SystemAlert;
use App\Services\MnotifySmsService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Unified SMS subsystem health check.
 *
 * Reports mNotify API connectivity, account credit balance,
 * pending/unreconciled queue depth, and system alert status.
 *
 * Usage: php artisan sms:health-check
 */
class SmsHealthCheck extends Command
{
    protected $signature = 'sms:health-check';

    protected $description = 'Diagnostic health check for the SMS subsystem';

    public function handle(): int
    {
        $this->line('');
        $this->line('=== SMS SUBSYSTEM HEALTH CHECK ===');
        $this->line('  Generated: '.now()->format('Y-m-d H:i:s'));
        $this->line('');

        $exitCode = self::SUCCESS;

        // ─── 1. mNotify API Connectivity ─────────────────────────
        $exitCode = $this->checkApiConnectivity() ? $exitCode : self::FAILURE;

        // ─── 2. Account Credit Balance ───────────────────────────
        $this->checkCreditBalance();

        // ─── 3. Pending / Unreconciled Queue Count ───────────────
        $this->checkPendingQueue();

        // ─── 4. System Alert Status ──────────────────────────────
        $this->checkSystemAlerts();

        $this->line('');

        return $exitCode;
    }

    /**
     * Ping mNotify API and report response latency.
     */
    private function checkApiConnectivity(): bool
    {
        $this->line('--- mNotify API Connectivity ---');

        $apiKey = config('services.mnotify.api_key');

        if (! $apiKey) {
            $this->warn('  API Key:     NOT CONFIGURED');
            $this->error('  Status:      UNHEALTHY (missing MNOTIFY_API_KEY)');

            return false;
        }

        $endpoint = rtrim(config('services.mnotify.base_url'), '/')."/balance?key={$apiKey}";

        $start = microtime(true);

        try {
            $response = Http::connectTimeout(5)->timeout(10)->get($endpoint);
            $latencyMs = round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $this->info("  Status:      HEALTHY (HTTP {$response->status()})");
                $this->line("  Latency:     {$latencyMs}ms");

                return true;
            }

            $this->error("  Status:      DEGRADED (HTTP {$response->status()})");
            $this->line("  Latency:     {$latencyMs}ms");

            return false;
        } catch (ConnectionException $e) {
            $latencyMs = round((microtime(true) - $start) * 1000);
            $this->error('  Status:      UNHEALTHY (connection failed)');
            $this->line("  Latency:     {$latencyMs}ms (timed out)");
            $this->line("  Error:       {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Query and display the current mNotify credit balance
     * with estimated days remaining.
     */
    private function checkCreditBalance(): void
    {
        $this->line('');
        $this->line('--- Account Credit Balance ---');

        $sms = app(MnotifySmsService::class);

        try {
            $balance = $sms->checkBalance();
        } catch (\Throwable $e) {
            $this->warn('  Balance:     UNAVAILABLE ('.$e->getMessage().')');

            return;
        }

        if ($balance === null) {
            $this->warn('  Balance:     UNAVAILABLE (no balance in response)');

            return;
        }

        $this->line('  Balance:     GH₵ '.number_format($balance, 2));

        // Estimate days remaining based on average daily SMS volume
        $dailyAvg = ScheduledSmsDelivery::where('created_at', '>=', now()->subDays(30))
            ->where('status', '!=', ScheduledSmsDelivery::STATUS_FAILED)
            ->count() / 30;

        if ($dailyAvg > 0) {
            $daysRemaining = (int) floor($balance / $dailyAvg);
            $this->line('  Daily avg:   ~'.number_format($dailyAvg, 1).' SMS/day (30-day window)');
            $this->line("  Est. days:   ~{$daysRemaining} days remaining");

            if ($daysRemaining <= 3) {
                $this->warn('  WARNING:     Credits critically low — replenish soon.');
            } elseif ($daysRemaining <= 7) {
                $this->warn('  NOTICE:      Credits below 7-day runway.');
            }
        } else {
            $this->line('  Daily avg:   No SMS sent in the last 30 days.');
            $this->line('  Est. days:   N/A');
        }
    }

    /**
     * Report the count of records needing reconciliation.
     */
    private function checkPendingQueue(): void
    {
        $this->line('');
        $this->line('--- Pending / Unreconciled Queue ---');

        $pendingApi = ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_PENDING_API)->count();
        $scheduledRemote = ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)->count();
        $pastDueUnreconciled = ScheduledSmsDelivery::whereIn('status', [
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            ScheduledSmsDelivery::STATUS_CANCELLED,
        ])->where('scheduled_at', '<', now())->count();

        $this->line("  Pending API:              {$pendingApi}");
        $this->line("  Scheduled (remote):       {$scheduledRemote}");
        $this->line("  Past-due (unreconciled):  {$pastDueUnreconciled}");

        if ($pastDueUnreconciled > 0) {
            $this->warn("  WARNING: {$pastDueUnreconciled} past-due delivery(ies) await reconciliation.");
        }
    }

    /**
     * Report the count of unacknowledged system alerts.
     */
    private function checkSystemAlerts(): void
    {
        $this->line('');
        $this->line('--- System Alert Status ---');

        $unread = SystemAlert::unread()->count();
        $creditAlerts = SystemAlert::unread()
            ->where('type', SystemAlert::TYPE_CREDIT_DEPLETION)
            ->count();
        $reconAlerts = SystemAlert::unread()
            ->where('type', SystemAlert::TYPE_RECONCILIATION)
            ->count();
        $totalAck = SystemAlert::whereNotNull('acknowledged_at')->count();

        $this->line("  Unread alerts:            {$unread}");
        $this->line("    Credit depletion:       {$creditAlerts}");
        $this->line("    Reconciliation:         {$reconAlerts}");
        $this->line("  Total acknowledged:       {$totalAck}");

        if ($unread > 0) {
            $this->warn("  NOTICE: {$unread} unacknowledged alert(s) require admin attention.");
        }
    }
}
