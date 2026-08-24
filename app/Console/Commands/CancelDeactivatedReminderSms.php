<?php

namespace App\Console\Commands;

use App\Jobs\CancelScheduledSmsJob;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use Illuminate\Console\Command;

/**
 * Cancel all future scheduled SMS linked to deactivated service
 * reminder settings.
 *
 * When a reminder automation is switched off in the admin panel, any
 * SMS already pushed to mNotify's remote schedule would still deliver.
 * This command finds those orphans and dispatches cancellation jobs
 * (DELETE /scheduled/{id}) using their stored mNotify job IDs.
 *
 * The ServiceReminderSettings observer handles this automatically on
 * deactivation; this command is the catch-up / repair tool for batches
 * created before that guard existed, or where a previous run failed.
 *
 * Idempotent: already-cancelled/dispatched/failed rows are never touched.
 */
class CancelDeactivatedReminderSms extends Command
{
    protected $signature = 'sms:cancel-deactivated-reminders
                            {--force : Run even when APP_ENV is local or testing}';

    protected $description = 'Cancel remote-scheduled SMS belonging to deactivated service reminders';

    public function handle(): int
    {
        // Live-SMS deployments set MNOTIFY_DRY_RUN=false explicitly. When
        // configured for real sends, cleanup must never silently skip —
        // even under APP_ENV=local, ghost schedules on mNotify must be
        // cancelled so members don't receive unwanted texts while the
        // system is powered off.
        $dryRunSetting = config('services.mnotify.dry_run');
        $liveSmsConfigured = $dryRunSetting !== null
            && filter_var($dryRunSetting, FILTER_VALIDATE_BOOLEAN) === false;

        if (app()->environment('local', 'testing') && ! $liveSmsConfigured && ! $this->option('force')) {
            $this->info('Skipping: APP_ENV is local/testing without MNOTIFY_DRY_RUN=false. Use --force to override.');

            return self::SUCCESS;
        }

        $deactivatedIds = ServiceReminderSettings::query()
            ->where('is_active', false)
            ->pluck('id');

        if ($deactivatedIds->isEmpty()) {
            $this->info('No deactivated reminder settings found.');

            return self::SUCCESS;
        }

        $deliveries = ScheduledSmsDelivery::query()
            ->where('source_type', 'reminder')
            ->whereIn('source_id', $deactivatedIds)
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_PENDING_API,
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            ])
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($deliveries->isEmpty()) {
            $this->info('No pending remote deliveries linked to deactivated reminders.');

            return self::SUCCESS;
        }

        $this->line("Found {$deliveries->count()} remote delivery(ies) linked to deactivated reminders:");

        $grouped = $deliveries->groupBy(fn ($d) => $d->scheduled_at->format('Y-m-d H:i'));
        foreach ($grouped as $when => $rows) {
            $this->line("  {$when}: {$rows->count()} message(s)");
        }

        foreach ($deliveries as $delivery) {
            CancelScheduledSmsJob::dispatch($delivery->id);
        }

        $this->info("Dispatched {$deliveries->count()} cancellation job(s) to mNotify.");

        return self::SUCCESS;
    }
}
