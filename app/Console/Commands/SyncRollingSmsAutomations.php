<?php

namespace App\Console\Commands;

use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\BirthdayMessageSettings;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use App\Models\SystemAlert;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Rolling pre-scheduler for dynamic SMS automations.
 *
 * Pushes birthday greetings and service reminders to mNotify's remote
 * scheduling API up to 14 days in advance. This decouples automated
 * SMS delivery from the local schedule:run cron, ensuring messages
 * deliver even when the church desktop is powered off.
 *
 * Pre-Sync Credit Guard:
 *   Before pushing any batch to mNotify, the command queries the
 *   account balance. If available credits < required credits, it
 *   marks the batch as failed_insufficient_credits and aborts —
 *   preventing silent delivery failures.
 *
 * Runs on:
 *   1. Container boot (docker/entrypoint.sh) — catches up after offline
 *   2. Daily at 05:00 via task scheduler — picks up new members/events
 *
 * Idempotent: re-running never creates duplicate scheduled requests.
 *
 * Usage: php artisan sms:sync-rolling-automations
 */
class SyncRollingSmsAutomations extends Command
{
    protected $signature = 'sms:sync-rolling-automations
                            {--days=14 : Number of days to pre-schedule ahead}
                            {--force : Run even when APP_ENV is local or testing}';

    protected $description = 'Pre-schedule dynamic SMS automations to mNotify for offline resilience';

    /**
     * Atomic lock key — prevents concurrent runs when entrypoint.sh
     * and the task scheduler both trigger this command simultaneously.
     * Lock held for 30s (typical run < 10s); acquire waits up to 5s.
     */
    private const LOCK_KEY = 'sms_sync_rolling_automations_lock';

    private const LOCK_TTL = 30;

    private const LOCK_WAIT = 5;

    public function handle(): int
    {
        // ─── Atomic execution lock ───────────────────────────────
        // Prevents race conditions when container boot (entrypoint.sh)
        // and the daily cron (05:00) fire simultaneously.
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        try {
            $lock->block(self::LOCK_WAIT);
        } catch (LockTimeoutException) {
            $this->warn('Another sms:sync-rolling-automations is already running. Skipping.');
            Log::info('sms:sync-rolling-automations: skipped — concurrent execution detected');

            return self::SUCCESS;
        }

        try {
            return $this->runSync();
        } finally {
            $lock->release();
        }
    }

    protected function runSync(): int
    {
        // Live-SMS deployments set MNOTIFY_DRY_RUN=false explicitly. When
        // configured for real sends, automations must never silently skip —
        // even under APP_ENV=local, a church PC still needs reminders pushed
        // to mNotify's remote queue so they deliver while powered off.
        $dryRunSetting = config('services.mnotify.dry_run');
        $liveSmsConfigured = $dryRunSetting !== null
            && filter_var($dryRunSetting, FILTER_VALIDATE_BOOLEAN) === false;

        if (app()->environment('local', 'testing') && ! $liveSmsConfigured && ! $this->option('force')) {
            $this->info('Skipping: APP_ENV is local/testing without MNOTIFY_DRY_RUN=false. Use --force to override.');

            return self::SUCCESS;
        }

        if (! config('services.mnotify.api_key')) {
            $this->error('mNotify API key not configured. Cannot pre-schedule automations.');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');

        $this->line("Rolling SMS sync: pre-scheduling automations for the next {$days} days...");

        $expiredCount = $this->expirePastDueDeliveries();

        // Collect all deliveries that need to be pushed to mNotify
        $birthdayIds = $this->collectBirthdayDeliveries($days);
        $reminderIds = $this->collectServiceReminderDeliveries($days);

        $totalDeliveries = count($birthdayIds) + count($reminderIds);

        if ($totalDeliveries === 0) {
            $this->info("Sync complete: {$expiredCount} expired, 0 new deliveries to push.");

            Log::info('sms:sync-rolling-automations completed', [
                'expired' => $expiredCount,
                'birthdays' => 0,
                'reminders' => 0,
                'days' => $days,
            ]);

            return self::SUCCESS;
        }

        // ─── Pre-Sync Credit Guard ──────────────────────────────
        // Query mNotify balance before pushing any jobs to prevent
        // silent delivery failures when credits are depleted.
        $sms = app(MnotifySmsService::class);
        $deliveryIds = array_merge($birthdayIds, $reminderIds);
        $guardResult = $this->validateCreditsBeforeDispatch($sms, $deliveryIds);

        if ($guardResult === false) {
            return self::FAILURE;
        }

        // Credits are sufficient — dispatch all jobs to the queue
        foreach ($deliveryIds as $deliveryId) {
            DispatchScheduledSmsToMnotifyJob::dispatch($deliveryId);
        }

        $birthdayCount = count($birthdayIds);
        $reminderCount = count($reminderIds);

        $this->info("Sync complete: {$expiredCount} expired, {$birthdayCount} birthday(s), {$reminderCount} reminder(s) queued on mNotify.");

        Log::info('sms:sync-rolling-automations completed', [
            'expired' => $expiredCount,
            'birthdays' => $birthdayCount,
            'reminders' => $reminderCount,
            'days' => $days,
        ]);

        return self::SUCCESS;
    }

    /**
     * Validate that mNotify account has sufficient credits for the batch.
     *
     * If credits are insufficient, marks all pending deliveries as
     * failed and creates a persistent SystemAlert for the admin dashboard.
     *
     * PHASE 3: System alerts are created on credit depletion, visible
     * in the admin UI until acknowledged by an admin.
     *
     * Returns true if credits are sufficient, false to abort.
     */
    protected function validateCreditsBeforeDispatch(MnotifySmsService $sms, array $deliveryIds): bool
    {
        try {
            $balance = $sms->checkBalance();
        } catch (\Throwable $e) {
            $this->warn("  Balance check failed ({$e->getMessage()}). Proceeding with dispatch — mNotify will reject if credits are depleted.");
            Log::warning('mNotify balance check failed — proceeding with dispatch', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        if ($balance === null) {
            $this->warn('  Could not retrieve mNotify balance. Proceeding with dispatch.');
            Log::warning('mNotify balance returned null — proceeding with dispatch');

            return true;
        }

        // Fetch all pending delivery message bodies to estimate credits
        $deliveries = ScheduledSmsDelivery::whereIn('id', $deliveryIds)->get();
        $messages = $deliveries->pluck('message_body')->toArray();
        $requiredCredits = $sms->estimateCredits($messages);

        $this->line("  mNotify balance: {$balance} | Required credits: {$requiredCredits}");

        if ($balance < $requiredCredits) {
            $this->error("  INSUFFICIENT CREDITS: Available {$balance}, Required {$requiredCredits}. Aborting push.");

            Log::critical('mNotify credit guard: insufficient credits for SMS batch', [
                'available_credits' => $balance,
                'required_credits' => $requiredCredits,
                'delivery_count' => count($deliveryIds),
                'shortfall' => $requiredCredits - $balance,
            ]);

            // PHASE 3: Create a persistent system alert for the admin dashboard.
            // Deduplication: skip if an unacknowledged credit_depletion alert
            // already exists — prevents duplicate rows across container restarts.
            $existingAlert = SystemAlert::where('type', SystemAlert::TYPE_CREDIT_DEPLETION)
                ->whereNull('acknowledged_at')
                ->exists();

            if (! $existingAlert) {
                SystemAlert::create([
                    'type' => SystemAlert::TYPE_CREDIT_DEPLETION,
                    'title' => 'SMS Credits Depleted',
                    'message' => "mNotify balance is GH₵ {$balance}, but ".count($deliveryIds)." messages (estimated {$requiredCredits} credits) need to be sent. No SMS were dispatched.",
                    'meta' => [
                        'balance' => $balance,
                        'credits_needed' => $requiredCredits,
                        'delivery_count' => count($deliveryIds),
                        'shortfall' => $requiredCredits - $balance,
                    ],
                ]);
            }

            // Mark all collected deliveries as failed
            ScheduledSmsDelivery::whereIn('id', $deliveryIds)
                ->update([
                    'status' => ScheduledSmsDelivery::STATUS_FAILED,
                    'error_message' => "Insufficient mNotify credits: available {$balance}, required {$requiredCredits}",
                ]);

            return false;
        }

        return true;
    }

    /**
     * Mark past-due unsent deliveries as cancelled. These represent
     * messages that were pre-scheduled but the scheduled time passed
     * while the system was offline.
     */
    protected function expirePastDueDeliveries(): int
    {
        $count = ScheduledSmsDelivery::query()
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_PENDING_API,
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            ])
            ->where('scheduled_at', '<', now())
            ->update([
                'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
                'error_message' => 'Expired: scheduled time passed while system was offline',
            ]);

        if ($count > 0) {
            $this->warn("  Expired {$count} past-due delivery(ies) that were never sent.");
        }

        return $count;
    }

    /**
     * Collect birthday greeting deliveries for the next N days.
     *
     * Creates ScheduledSmsDelivery records with pending_api status
     * but does NOT dispatch jobs — the caller handles dispatching
     * after credit validation.
     *
     * Returns array of delivery IDs created.
     */
    protected function collectBirthdayDeliveries(int $days): array
    {
        if (! config('church.birthday.enabled')) {
            $this->line('  Birthday greetings disabled — skipping.');

            return [];
        }

        $ids = [];
        $today = now()->startOfDay();
        $churchName = config('church.name', 'Wesleyan International Society');

        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $scheduledAt = $date->copy()->hour(7)->minute(0)->second(0);

            if ($scheduledAt->isPast()) {
                continue;
            }

            $members = Member::eligibleForSms()
                ->whereNotNull('date_of_birth')
                ->whereRaw('EXTRACT(MONTH FROM date_of_birth) = ?', [$date->month])
                ->whereRaw('EXTRACT(DAY FROM date_of_birth) = ?', [$date->day])
                ->get();

            foreach ($members as $member) {
                if ($this->isAlreadyScheduled('birthday', $member->id, null, $scheduledAt)) {
                    continue;
                }

                $settings = BirthdayMessageSettings::forBranch($member->branch_id);
                $body = $settings->render($member, $churchName);

                $delivery = ScheduledSmsDelivery::create([
                    'branch_id' => $member->branch_id,
                    'phone' => $member->phone,
                    'message_body' => $body,
                    'scheduled_at' => $scheduledAt,
                    'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
                    'source_type' => 'birthday',
                    'source_id' => $member->id,
                ]);

                $ids[] = $delivery->id;
            }
        }

        $this->line('  Collected '.count($ids).' birthday greeting(s) for dispatch.');

        return $ids;
    }

    /**
     * Collect service reminder deliveries for the next N days.
     *
     * Creates ScheduledSmsDelivery records with pending_api status
     * but does NOT dispatch jobs — the caller handles dispatching
     * after credit validation.
     *
     * Returns array of delivery IDs created.
     */
    protected function collectServiceReminderDeliveries(int $days): array
    {
        $ids = [];
        $today = now()->startOfDay();

        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $dow = $date->dayOfWeek;

            $settings = ServiceReminderSettings::query()
                ->with(['serviceType', 'branch'])
                ->where('is_active', true)
                ->where('send_day_of_week', $dow)
                ->get();

            foreach ($settings as $setting) {
                $scheduledAt = $date->copy()->hour($setting->send_hour)->minute(0)->second(0);

                if ($scheduledAt->isPast()) {
                    continue;
                }

                $intendedDate = $this->computeIntendedServiceDate($setting, $date);
                $serviceName = $setting->serviceType?->name ?? 'Service';
                $churchName = $setting->branch?->name ?? config('church.name', 'Your church');
                $serviceTime = $setting->serviceTimeLabel();

                $members = Member::eligibleForSms()
                    ->where('branch_id', $setting->branch_id)
                    ->get();

                foreach ($members as $member) {
                    if ($this->isAlreadyScheduled('reminder', $setting->id, $member->phone, $scheduledAt)) {
                        continue;
                    }

                    $body = $setting->render($member, $serviceName, $intendedDate, $serviceTime, $churchName);

                    $delivery = ScheduledSmsDelivery::create([
                        'branch_id' => $setting->branch_id,
                        'phone' => $member->phone,
                        'message_body' => $body,
                        'scheduled_at' => $scheduledAt,
                        'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
                        'source_type' => 'reminder',
                        'source_id' => $setting->id,
                    ]);

                    $ids[] = $delivery->id;
                }
            }
        }

        $this->line('  Collected '.count($ids).' service reminder(s) for dispatch.');

        return $ids;
    }

    /**
     * Idempotency check: has this event already been scheduled
     * (or dispatched) for the given date — or was it explicitly
     * cancelled/defused?
     *
     * Live statuses prevent duplicate pushes on repeated runs.
     * Cancelled statuses act as tombstones so a re-run never
     * resurrects a delivery an admin deliberately cancelled or
     * that the system defused against mNotify.
     */
    protected function isAlreadyScheduled(
        string $sourceType,
        ?string $sourceId,
        ?string $phone,
        Carbon $scheduledAt,
    ): bool {
        $query = ScheduledSmsDelivery::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereDate('scheduled_at', $scheduledAt->toDateString())
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_PENDING_API,
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
                ScheduledSmsDelivery::STATUS_DISPATCHED,
                ScheduledSmsDelivery::STATUS_CANCELLED,
                ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
            ]);

        if ($phone !== null) {
            $query->where('phone', $phone);
        }

        return $query->exists();
    }

    /**
     * Compute the intended service date from a reminder settings
     * row and the send date. Mirrors the DOW mapping used by
     * SendServiceReminders.
     */
    protected function computeIntendedServiceDate(
        ServiceReminderSettings $settings,
        Carbon $date,
    ): Carbon {
        $serviceDow = match ($settings->serviceType?->slug) {
            'sunday_adult', 'sunday_children' => Carbon::SUNDAY,
            'midweek_service', 'bible_study' => Carbon::WEDNESDAY,
            'prayer_meeting' => Carbon::FRIDAY,
            default => Carbon::SUNDAY,
        };

        $d = $date->copy()->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            if ($d->dayOfWeek === $serviceDow) {
                return $d;
            }
            $d->addDay();
        }

        return $date->copy()->startOfDay();
    }
}
