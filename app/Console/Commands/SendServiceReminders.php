<?php

namespace App\Console\Commands;

use App\Jobs\SendBroadcastMessageJob;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\ServiceReminderLog;
use App\Models\ServiceReminderSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send the pre-service SMS reminder for any service whose reminder
 * is configured to fire RIGHT NOW (matching day-of-week + hour).
 *
 * Scheduled to run every hour. The command itself filters by
 * day-of-week and current hour, so most hourly runs do nothing.
 * When a configured slot matches, every active member with a phone
 * gets the rendered template.
 *
 * Idempotent: a member is never sent two reminders for the same
 * service_type + intended_service_date pair, so re-running within
 * the same hour is safe.
 */
class SendServiceReminders extends Command
{
    protected $signature = 'reminders:send
                            {--at= : Override "now" as YYYY-MM-DD HH:MM (testing)}
                            {--force : Run even when APP_ENV is local or testing}';

    protected $description = 'Send pre-service SMS reminders to all active members';

    public function handle(): int
    {
        // Live-SMS deployments set MNOTIFY_DRY_RUN=false explicitly. When
        // configured for real sends, reminders must never silently skip —
        // even under APP_ENV=local, a church PC still needs its scheduled
        // fan-out to fire at the configured service hour.
        $dryRunSetting = config('services.mnotify.dry_run');
        $liveSmsConfigured = $dryRunSetting !== null
            && filter_var($dryRunSetting, FILTER_VALIDATE_BOOLEAN) === false;

        if (app()->environment('local', 'testing') && ! $liveSmsConfigured && ! $this->option('force')) {
            $this->info('Skipping: APP_ENV is local/testing without MNOTIFY_DRY_RUN=false. Use --force to override.');

            return self::SUCCESS;
        }

        $now = $this->option('at')
            ? Carbon::parse($this->option('at'))
            : now();

        // Postgres EXTRACT(DOW) returns 0=Sunday..6=Saturday, matching
        // our send_day_of_week column convention.
        $dow = $now->dayOfWeek;
        $hour = $now->hour;

        // Find all settings rows whose schedule matches RIGHT NOW.
        // Across all branches (multi-tenant safe — the trait will scope
        // member queries below).
        $matches = ServiceReminderSettings::query()
            ->with(['serviceType', 'branch'])
            ->where('is_active', true)
            ->where('send_day_of_week', $dow)
            ->where('send_hour', $hour)
            ->get();

        if ($matches->isEmpty()) {
            $this->info("No reminders configured for {$now->format('l H:00')}.");

            return self::SUCCESS;
        }

        $totalSent = 0;
        $totalNoPhone = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach ($matches as $settings) {
            $stats = $this->dispatchForSettings($settings, $now);
            $totalSent += $stats['sent'];
            $totalNoPhone += $stats['no_phone'];
            $totalSkipped += $stats['skipped'];
            $totalFailed += $stats['failed'];
        }

        $this->info("Reminders dispatched: {$totalSent} sent, {$totalNoPhone} no-phone, {$totalSkipped} idempotent-skip, {$totalFailed} failed.");
        Log::info('Service reminders run', [
            'at' => $now->toDateTimeString(),
            'matched_settings' => $matches->count(),
            'sent' => $totalSent,
        ]);

        return self::SUCCESS;
    }

    /**
     * Send the reminder for one settings row to all active members of
     * the branch. Returns counts for the caller to roll up.
     *
     * @return array{sent: int, no_phone: int, skipped: int, failed: int}
     */
    protected function dispatchForSettings(ServiceReminderSettings $settings, Carbon $now): array
    {
        $sent = 0;
        $noPhone = 0;
        $skipped = 0;
        $failed = 0;

        // The service this reminder is FOR. If reminder fires Saturday
        // (DOW 6) for a Sunday service (DOW 0), the intended date is
        // tomorrow. If reminder fires Wednesday morning for a Wednesday
        // service, intended date is today.
        $intendedDate = $this->computeIntendedServiceDate($settings, $now);

        $members = Member::query()
            ->where('branch_id', $settings->branch_id)
            ->where('status', 'active')
            ->get();

        if ($members->isEmpty()) {
            return compact('sent', 'noPhone', 'skipped', 'failed') + [
                'sent' => 0,
                'no_phone' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }

        $serviceName = $settings->serviceType?->name ?? 'Service';
        $churchName = $settings->branch?->name ?? config('church.name', 'Your church');
        $serviceTime = $settings->serviceTimeLabel();

        foreach ($members as $member) {
            // Idempotency: already sent THIS reminder for THIS service date.
            if (ServiceReminderLog::alreadySent($member->id, $settings->service_type_id, $intendedDate)) {
                $skipped++;

                continue;
            }

            if (empty($member->phone)) {
                ServiceReminderLog::create([
                    'branch_id' => $member->branch_id,
                    'service_type_id' => $settings->service_type_id,
                    'member_id' => $member->id,
                    'sent_at' => now(),
                    'intended_service_date' => $intendedDate,
                    'status' => ServiceReminderLog::STATUS_NO_PHONE,
                ]);
                $noPhone++;

                continue;
            }

            try {
                $body = $settings->render(
                    $member,
                    $serviceName,
                    $intendedDate,
                    $serviceTime,
                    $churchName,
                );

                $message = Message::create([
                    'branch_id' => $member->branch_id,
                    'sender_id' => null,
                    'subject' => "Reminder: {$serviceName}",
                    'body' => $body,
                    'channel' => 'sms',
                    'status' => 'sent',
                    'recipient_group' => 'service_reminder',
                    'sent_at' => now(),
                ]);

                $recipient = MessageRecipient::create([
                    'message_id' => $message->id,
                    'member_id' => $member->id,
                    'phone' => $member->phone,
                    'email' => $member->email,
                    'delivery_status' => 'pending',
                ]);

                SendBroadcastMessageJob::dispatch($recipient->id);

                ServiceReminderLog::create([
                    'branch_id' => $member->branch_id,
                    'service_type_id' => $settings->service_type_id,
                    'member_id' => $member->id,
                    'sent_at' => now(),
                    'intended_service_date' => $intendedDate,
                    'status' => ServiceReminderLog::STATUS_SENT,
                    'phone_used' => $member->phone,
                    'message_body' => $body,
                ]);

                $sent++;
            } catch (Throwable $e) {
                ServiceReminderLog::create([
                    'branch_id' => $member->branch_id,
                    'service_type_id' => $settings->service_type_id,
                    'member_id' => $member->id,
                    'sent_at' => now(),
                    'intended_service_date' => $intendedDate,
                    'status' => ServiceReminderLog::STATUS_FAILED,
                    'phone_used' => $member->phone,
                    'error_message' => $e->getMessage(),
                ]);
                $failed++;

                Log::error('Service reminder failed for member '.$member->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'sent' => $sent,
            'no_phone' => $noPhone,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * Figure out which date the reminder is FOR. The "service" happens
     * on the same DOW as send_day_of_week if hours align (e.g.
     * Wednesday morning reminder for Wednesday evening service), or on
     * the FOLLOWING occurrence (e.g. Saturday reminder for Sunday).
     *
     * Rule: the next occurrence of the service type's natural day at or
     * after the reminder fire moment. For most use cases this is:
     *   - Same day if the service is later today (midweek case)
     *   - Tomorrow if the reminder fires the day before (Sunday case)
     */
    protected function computeIntendedServiceDate(ServiceReminderSettings $settings, Carbon $now): Carbon
    {
        // Use the service_type's natural day-of-week. We infer it from
        // the slug — Sunday services on Sunday, midweek on Wednesday.
        $serviceDow = $this->serviceTypeNaturalDow($settings->serviceType?->slug);

        $date = $now->copy()->startOfDay();

        // Walk forward at most 7 days to find the next occurrence.
        for ($i = 0; $i < 7; $i++) {
            if ($date->dayOfWeek === $serviceDow) {
                return $date;
            }
            $date->addDay();
        }

        // Fallback (shouldn't reach here).
        return $now->copy()->startOfDay();
    }

    /**
     * Map a service type slug to its natural day of week. Hardcoded
     * mapping for known service types; defaults to Sunday for unknown.
     */
    protected function serviceTypeNaturalDow(?string $slug): int
    {
        return match ($slug) {
            'sunday_adult', 'sunday_children' => Carbon::SUNDAY,
            'midweek_service', 'bible_study' => Carbon::WEDNESDAY,
            'prayer_meeting' => Carbon::FRIDAY,
            default => Carbon::SUNDAY,
        };
    }
}
