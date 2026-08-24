<?php

namespace App\Console\Commands;

use App\Exceptions\TransientSmsException;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retry pending mNotify API requests that failed due to network issues.
 *
 * Designed to run every 5 minutes via the task scheduler. Picks up
 * all pending_remote_schedules records that haven't exhausted retries
 * and re-dispatches them.
 *
 * Safe to re-run (idempotent): processing records are skipped.
 *
 * Usage: php artisan sync:pending-schedules
 */
class SyncPendingRemoteSchedules extends Command
{
    protected $signature = 'sync:pending-schedules
                            {--dry-run : Show what would be synced without dispatching}
                            {--force : Run even when APP_ENV is local or testing}';

    protected $description = 'Retry failed mNotify API calls from the offline queue';

    public function handle(): int
    {
        // Live-SMS deployments set MNOTIFY_DRY_RUN=false explicitly. When
        // configured for real sends, offline retries must never silently
        // skip — even under APP_ENV=local, queued pushes to mNotify's
        // remote queue must drain so nothing is lost while powered off.
        $dryRunSetting = config('services.mnotify.dry_run');
        $liveSmsConfigured = $dryRunSetting !== null
            && filter_var($dryRunSetting, FILTER_VALIDATE_BOOLEAN) === false;

        if (app()->environment('local', 'testing') && ! $liveSmsConfigured && ! $this->option('force')) {
            $this->info('Skipping: APP_ENV is local/testing without MNOTIFY_DRY_RUN=false. Use --force to override.');

            return self::SUCCESS;
        }

        $pending = PendingRemoteSchedule::forSync()->get();

        if ($pending->isEmpty()) {
            $this->info('No pending schedules to sync.');

            return self::SUCCESS;
        }

        $this->info("Found {$pending->count()} pending schedule(s) to sync.");

        $synced = 0;
        $failed = 0;

        foreach ($pending as $item) {
            $item->markProcessing();

            $line = "  [{$item->action}] delivery={$item->scheduled_sms_delivery_id} attempts={$item->attempts}/{$item->max_attempts}";

            if ($this->option('dry-run')) {
                $this->line($line.' [DRY RUN]');
                $synced++;

                continue;
            }

            try {
                match ($item->action) {
                    PendingRemoteSchedule::ACTION_SCHEDULE => $this->retrySchedule($item),
                    PendingRemoteSchedule::ACTION_CANCEL => $this->retryCancel($item),
                    PendingRemoteSchedule::ACTION_UPDATE => $this->retryUpdate($item),
                    default => null,
                };

                $item->recordAttempt();
                $synced++;
                $this->line($line.' [OK]');
            } catch (\Throwable $e) {
                $item->recordAttempt($e->getMessage());
                $failed++;
                $this->line($line." [FAILED: {$e->getMessage()}]");

                Log::warning("Sync retry failed for pending schedule #{$item->id}: ".$e->getMessage());
            }
        }

        $this->info("Sync complete: {$synced} synced, {$failed} failed.");
        Log::info('Pending schedules sync run', compact('synced', 'failed'));

        return self::SUCCESS;
    }

    protected function retrySchedule(PendingRemoteSchedule $item): void
    {
        $payload = $item->payload;
        $sms = app(MnotifySmsService::class);

        $scheduledAt = Carbon::parse($payload['scheduled_at']);
        $mnotifyJobId = $sms->schedule($payload['phone'], $payload['message_body'], $scheduledAt);

        if ($mnotifyJobId && $item->scheduled_sms_delivery_id) {
            $delivery = ScheduledSmsDelivery::find($item->scheduled_sms_delivery_id);
            if ($delivery && $delivery->status === ScheduledSmsDelivery::STATUS_PENDING_API) {
                $delivery->markScheduledRemote($mnotifyJobId);
            }
        }
    }

    protected function retryCancel(PendingRemoteSchedule $item): void
    {
        $payload = $item->payload;
        $sms = app(MnotifySmsService::class);
        $jobId = (string) $payload['mnotify_job_id'];

        // Resolve the authoritative remote listing handle (push-time
        // references don't match mNotify's numeric _ids).
        if ($item->scheduled_sms_delivery_id) {
            $delivery = ScheduledSmsDelivery::find($item->scheduled_sms_delivery_id);

            if ($delivery && $delivery->message_body && $delivery->scheduled_at) {
                $resolved = $sms->resolveScheduledJobId($delivery->message_body, $delivery->scheduled_at);

                if ($resolved !== null) {
                    $jobId = $resolved;

                    if ($resolved !== $payload['mnotify_job_id']) {
                        $delivery->update(['mnotify_job_id' => $resolved]);
                    }
                }
            }
        }

        try {
            $cancelled = $sms->cancelScheduled($jobId);

            if (! $cancelled) {
                // DELETE permanently refused (404 gone / 405 rejected /
                // already dispatched) — defusal is the last resort
                // before accepting a local-only cancellation.
                $cancelled = $sms->defuseScheduled($jobId);
            }
        } catch (TransientSmsException $e) {
            // DELETE endpoint outage (HTTP 500 / network). Try defusing
            // before giving up; if defusal also fails transiently the
            // original error propagates so this item stays queued and
            // is retried on the next sync cycle.
            try {
                $cancelled = $sms->defuseScheduled($jobId);
            } catch (TransientSmsException) {
                throw $e;
            }

            if (! $cancelled) {
                throw $e;
            }
        }

        if ($item->scheduled_sms_delivery_id) {
            $delivery = ScheduledSmsDelivery::find($item->scheduled_sms_delivery_id);
            if ($delivery && $delivery->isCancellable()) {
                if ($cancelled) {
                    $delivery->markCancelledRemote();
                } else {
                    $delivery->markCancelled();
                }
            }
        }
    }

    protected function retryUpdate(PendingRemoteSchedule $item): void
    {
        $payload = $item->payload;
        $sms = app(MnotifySmsService::class);

        $scheduledAt = Carbon::parse($payload['scheduled_at']);
        $sms->updateScheduled(
            $payload['mnotify_job_id'],
            $payload['phone'],
            $payload['message_body'],
            $scheduledAt,
        );
    }
}
