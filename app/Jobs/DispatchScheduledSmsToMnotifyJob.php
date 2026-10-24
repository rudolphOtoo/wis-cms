<?php

namespace App\Jobs;

use App\Exceptions\TransientSmsException;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Push a single scheduled SMS to mNotify's remote scheduling API.
 *
 * On success: marks the ScheduledSmsDelivery as 'scheduled_remote' with
 * the mNotify job ID. On network/server failure: creates a
 * PendingRemoteSchedule record so the sync command retries later.
 *
 * This decouples the scheduling API call from the HTTP request lifecycle,
 * making it resilient to transient network issues.
 */
class DispatchScheduledSmsToMnotifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public string $scheduledSmsDeliveryId,
    ) {}

    public function handle(MnotifySmsService $sms): void
    {
        $delivery = ScheduledSmsDelivery::find($this->scheduledSmsDeliveryId);

        if (! $delivery) {
            return;
        }

        // Already processed (idempotent)
        if ($delivery->status !== ScheduledSmsDelivery::STATUS_PENDING_API) {
            return;
        }

        // Prevent duplicate pushes for the same member on the same target date
        $lockKey = 'sms_dispatch_'.md5($delivery->phone.'|'.$delivery->scheduled_at->format('Y-m-d'));
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            // Another dispatch is in progress; re-check state
            $delivery->refresh();
            if ($delivery->status !== ScheduledSmsDelivery::STATUS_PENDING_API) {
                return;
            }
        }

        try {
            $delivery->refresh();
            if ($delivery->status !== ScheduledSmsDelivery::STATUS_PENDING_API) {
                return;
            }

            // Local pre-push idempotency: another delivery already scheduled/active for same phone+date
            $existingActive = ScheduledSmsDelivery::query()
                ->where('phone', $delivery->phone)
                ->whereDate('scheduled_at', $delivery->scheduled_at)
                ->where('id', '!=', $delivery->id)
                ->where(function ($q) {
                    $q->whereIn('status', [
                        ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
                        ScheduledSmsDelivery::STATUS_DISPATCHED,
                    ])->orWhere(function ($q2) {
                        $q2->where('status', ScheduledSmsDelivery::STATUS_PENDING_API)
                            ->whereNotNull('mnotify_job_id')
                            ->where('mnotify_job_id', '<>', '');
                    });
                })
                ->exists();

            if ($existingActive) {
                Log::warning("Scheduled SMS #{$delivery->id} skipped: duplicate already exists for {$delivery->phone} on ".$delivery->scheduled_at->toDateString());
                $delivery->markCancelled();

                return;
            }

            $mnotifyJobId = $sms->schedule(
                $delivery->phone,
                $delivery->message_body,
                $delivery->scheduled_at,
            );

            if ($mnotifyJobId !== null && $mnotifyJobId !== '') {
                $delivery->markScheduledRemote($mnotifyJobId);
                Log::info("Scheduled SMS #{$delivery->id} confirmed by mNotify as job #{$mnotifyJobId}");
            } else {
                // Permanent failure — mNotify rejected the request
                $delivery->markFailed('mNotify did not return a job ID');
                Log::error("Scheduled SMS #{$delivery->id} rejected by mNotify (no job ID)");
            }
        } catch (TransientSmsException $e) {
            // Network or server error — queue for later retry
            $this->createPendingSchedule($delivery, $e->getMessage());
            throw $e; // Let the queue worker handle retry logic
        } finally {
            if (isset($lock) && method_exists($lock, 'owner') ? $lock->owner() === getmypid() || true : false) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
    }

    /**
     * Create a pending_remote_schedules record so the sync command
     * can retry this API call when connectivity returns.
     */
    protected function createPendingSchedule(ScheduledSmsDelivery $delivery, string $error): void
    {
        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => [
                'phone' => $delivery->phone,
                'message_body' => $delivery->message_body,
                'scheduled_at' => $delivery->scheduled_at->toIso8601String(),
                'branch_id' => $delivery->branch_id,
                'source_type' => $delivery->source_type,
                'source_id' => $delivery->source_id,
            ],
            'error_message' => $error,
        ]);

        Log::info("Scheduled SMS #{$delivery->id} queued for offline retry: {$error}");
    }
}
