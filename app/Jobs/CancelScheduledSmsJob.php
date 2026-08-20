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
 * Cancel a scheduled SMS on mNotify's remote API.
 *
 * Used when an admin deletes or edits a scheduled message in the local
 * CMS. The job ensures the remote schedule is cleaned up to prevent
 * ghost SMS from being delivered.
 */
class CancelScheduledSmsJob implements ShouldQueue
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

        if (! $delivery || ! $delivery->isCancellable()) {
            return;
        }

        // If we never got a mNotify job ID, just mark locally
        if (! $delivery->mnotify_job_id) {
            $delivery->markCancelled();

            return;
        }

        try {
            $cancelled = $sms->cancelScheduled($delivery->mnotify_job_id);

            if ($cancelled) {
                $delivery->markCancelled();
                Log::info("Cancelled remote SMS job #{$delivery->mnotify_job_id}");
            } else {
                Log::warning("mNotify could not cancel job #{$delivery->mnotify_job_id} (may already be dispatched)");
                // Mark cancelled locally even if mNotify says no —
                // the job may have already been sent or removed.
                $delivery->markCancelled();
            }
        } catch (TransientSmsException $e) {
            $this->createPendingCancel($delivery, $e->getMessage());
            throw $e;
        }
    }

    protected function createPendingCancel(ScheduledSmsDelivery $delivery, string $error): void
    {
        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_CANCEL,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => [
                'mnotify_job_id' => $delivery->mnotify_job_id,
            ],
            'error_message' => $error,
        ]);
    }
}
