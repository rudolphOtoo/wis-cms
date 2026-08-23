<?php

namespace App\Console\Commands;

use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\BirthdayMessageSettings;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rolling pre-scheduler for dynamic SMS automations.
 *
 * Pushes birthday greetings and service reminders to mNotify's remote
 * scheduling API up to 14 days in advance. This decouples automated
 * SMS delivery from the local schedule:run cron, ensuring messages
 * deliver even when the church desktop is powered off.
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

    public function handle(): int
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
        $birthdayCount = $this->syncBirthdayAutomations($days);
        $reminderCount = $this->syncServiceReminderAutomations($days);

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
     * Pre-schedule birthday greetings for members with birthdays
     * in the next N days. Each greeting is scheduled for 07:00 on
     * the member's birthday via mNotify's remote scheduling API.
     */
    protected function syncBirthdayAutomations(int $days): int
    {
        if (! config('church.birthday.enabled')) {
            $this->line('  Birthday greetings disabled — skipping.');

            return 0;
        }

        $count = 0;
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

                DispatchScheduledSmsToMnotifyJob::dispatch($delivery->id);
                $count++;
            }
        }

        $this->line("  Pre-scheduled {$count} birthday greeting(s).");

        return $count;
    }

    /**
     * Pre-schedule service reminders for all active settings
     * in the next N days. For each day, find settings whose
     * send_day_of_week matches and push scheduled SMS to mNotify.
     */
    protected function syncServiceReminderAutomations(int $days): int
    {
        $count = 0;
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

                    DispatchScheduledSmsToMnotifyJob::dispatch($delivery->id);
                    $count++;
                }
            }
        }

        $this->line("  Pre-scheduled {$count} service reminder(s).");

        return $count;
    }

    /**
     * Idempotency check: has this event already been scheduled
     * (or dispatched) for the given date?
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
