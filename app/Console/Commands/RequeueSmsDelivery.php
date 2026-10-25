<?php

namespace App\Console\Commands;

use App\Exceptions\TransientSmsException;
use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Requeue one delivery that never reached mNotify.
 *
 * This is the narrow repair for a row the recipient-day guard cancelled
 * before the guard became source-aware, or a failed row that needs one
 * deliberate retry. Unlike sms:requeue-day it does not require the whole
 * date to be empty in the cloud: it refuses when mNotify already holds
 * this exact message at this exact time, and when another active row of
 * the same source already owns the phone/day slot.
 */
class RequeueSmsDelivery extends Command
{
    protected $signature = 'sms:requeue-delivery
                            {delivery : The scheduled_sms_delivery ID}
                            {--execute : Reset and push this delivery (default: dry run)}';

    protected $description = 'Requeue one local delivery when mNotify does not already hold that exact message';

    public function handle(MnotifySmsService $sms): int
    {
        $delivery = ScheduledSmsDelivery::find((string) $this->argument('delivery'));

        if (! $delivery) {
            $this->error('Delivery not found.');

            return self::FAILURE;
        }

        $scheduledAt = $delivery->scheduled_at->copy();

        $this->line('=== SMS DELIVERY REQUEUE ===');
        $this->line('Delivery: '.$delivery->id);
        $this->line('Date: '.$scheduledAt->format('Y-m-d H:i'));
        $this->line('Phone: '.$delivery->phone);
        $this->line('Source: '.(string) $delivery->source_type);
        $this->line('Status: '.$delivery->status);
        $this->line('Mode: '.($this->option('execute') ? 'EXECUTE (reset and push)' : 'DRY RUN (use --execute to apply)'));
        $this->line('');

        if ($scheduledAt->isPast()) {
            $this->error('This delivery is in the past. Only future deliveries can be requeued.');

            return self::FAILURE;
        }

        if (in_array($delivery->status, [
            ScheduledSmsDelivery::STATUS_DISPATCHED,
            ScheduledSmsDelivery::STATUS_FAILED_PROVIDER,
        ], true)) {
            $this->error('This delivery is already terminal ('.$delivery->status.'). Refusing to resend it.');

            return self::FAILURE;
        }

        if ($delivery->isProviderBacked()) {
            $this->error('This delivery is already backed by an mNotify job ('.$delivery->mnotify_job_id.'). Refusing to duplicate it.');

            return self::FAILURE;
        }

        if (ScheduledSmsDelivery::recipientDayAlreadyScheduled(
            $delivery->phone,
            $scheduledAt,
            $delivery->id,
            $delivery->source_type,
        )) {
            $this->error('Another active '.(string) $delivery->source_type.' delivery already owns this phone and day. Refusing to duplicate it.');

            return self::FAILURE;
        }

        try {
            $remote = collect($sms->fetchScheduledJobs());
        } catch (TransientSmsException $e) {
            $this->error('Could not read the mNotify schedule. Aborting — nothing was changed.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $exact = $this->exactRemoteJob($sms, $remote, $delivery);

        if ($exact !== null) {
            $this->error('mNotify already holds this exact message at '.$scheduledAt->format('Y-m-d H:i').' (job '.($exact['_id'] ?? 'unknown').'). Refusing to duplicate it.');

            return self::FAILURE;
        }

        $this->line('mNotify does not hold this exact message. Safe to push.');

        if (! $this->option('execute')) {
            $this->line('');
            $this->comment('Dry run. This delivery would be reset to pending_api and pushed.');
            $this->comment('Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        [$queueCleared] = DB::transaction(function () use ($delivery) {
            $queueCleared = PendingRemoteSchedule::query()
                ->where('scheduled_sms_delivery_id', $delivery->id)
                ->whereIn('status', [
                    PendingRemoteSchedule::STATUS_PENDING,
                    PendingRemoteSchedule::STATUS_PROCESSING,
                ])
                ->update([
                    'status' => PendingRemoteSchedule::STATUS_COMPLETED,
                    'error_message' => 'Superseded by sms:requeue-delivery',
                    'updated_at' => now(),
                ]);

            $delivery->update([
                'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
                'mnotify_job_id' => null,
                'error_message' => 'Requeued by sms:requeue-delivery',
                'updated_at' => now(),
            ]);

            return [$queueCleared];
        });

        $this->line('');
        $this->line("Cleared old retry items: {$queueCleared}");

        try {
            DispatchScheduledSmsToMnotifyJob::dispatchSync($delivery->id);
        } catch (\Throwable $e) {
            $delivery->refresh();

            $this->error('Push failed: '.$e->getMessage());
            $this->line('Status: '.$delivery->status.'. A retry was queued if the provider was unreachable.');

            Log::warning('sms:requeue-delivery push failed', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $delivery->refresh();

        if ($delivery->status === ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE) {
            $this->info("Scheduled on mNotify as job {$delivery->mnotify_job_id}.");

            try {
                $active = $this->activeJobsOnDate($sms, $sms->refreshScheduledJobs(), $scheduledAt);

                $this->line("mNotify now holds {$active} active job(s) for ".$scheduledAt->toDateString().'.');
            } catch (TransientSmsException $e) {
                $this->warn('Scheduled, but could not re-read the cloud schedule: '.$e->getMessage());
            }

            Log::info('sms:requeue-delivery executed', [
                'delivery_id' => $delivery->id,
                'mnotify_job_id' => $delivery->mnotify_job_id,
                'queue_cleared' => $queueCleared,
            ]);

            return self::SUCCESS;
        }

        $this->error('Push did not schedule the delivery. Status: '.$delivery->status.'. '
            .(string) $delivery->error_message);

        return self::FAILURE;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $jobs
     * @return array<string, mixed>|null
     */
    protected function exactRemoteJob(MnotifySmsService $sms, Collection $jobs, ScheduledSmsDelivery $delivery): ?array
    {
        $dateTime = $delivery->scheduled_at->format('Y-m-d H:i:s');
        $body = trim((string) $delivery->message_body);

        return $jobs->first(function (array $job) use ($sms, $dateTime, $body) {
            if ($sms->isDefusedScheduledJob($job) || ! $sms->isFutureScheduledJob($job)) {
                return false;
            }

            return (string) ($job['date_time'] ?? '') === $dateTime
                && trim((string) ($job['message'] ?? '')) === $body;
        });
    }

    /**
     * @param  iterable<array<string, mixed>>  $jobs
     */
    protected function activeJobsOnDate(MnotifySmsService $sms, iterable $jobs, Carbon $day): int
    {
        return collect($jobs)
            ->filter(function (array $job) use ($sms, $day) {
                if ($sms->isDefusedScheduledJob($job) || ! $sms->isFutureScheduledJob($job)) {
                    return false;
                }

                try {
                    return Carbon::parse((string) ($job['date_time'] ?? ''))->isSameDay($day);
                } catch (\Throwable) {
                    return false;
                }
            })
            ->count();
    }
}
