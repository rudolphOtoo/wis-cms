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

        try {
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
