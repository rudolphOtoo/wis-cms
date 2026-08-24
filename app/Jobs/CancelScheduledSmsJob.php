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

        // mNotify's push API returns a reference that differs from the
        // numeric handle its listing/cancel endpoints use. Resolve the
        // authoritative remote handle by content before operating, and
        // persist it so future operations (and audits) line up.
        $jobId = $sms->resolveScheduledJobId($delivery->message_body, $delivery->scheduled_at)
            ?? (string) $delivery->mnotify_job_id;

        if ($jobId !== $delivery->mnotify_job_id) {
            $delivery->update(['mnotify_job_id' => $jobId]);
        }

        try {
            $cancelled = $sms->cancelScheduled($jobId);
            $defuseAttempted = false;

            if (! $cancelled) {
                // DELETE permanently refused (HTTP 404 gone, 405 method
                // rejected, or mNotify says the job already fired). Try
                // the defusal fallback before accepting a local-only
                // cancellation, so a ghost job can't keep living on
                // mNotify's servers.
                $defuseAttempted = true;
                $cancelled = $this->attemptDefusal($sms, $delivery, $jobId);

                if (! $cancelled) {
                    $delivery->update([
                        'error_message' => 'Cancelled locally; remote defusal refused (job may already be dispatched)',
                    ]);
                }
            }

            if ($cancelled) {
                $delivery->markCancelledRemote();
                Log::info("Cancelled remote SMS job #{$delivery->mnotify_job_id}");

                return;
            }

            Log::warning("mNotify could not cancel job #{$delivery->mnotify_job_id} (may already be dispatched)");
            // Mark cancelled locally even if mNotify says no —
            // the job may have already been sent or removed.
            $delivery->markCancelled();
        } catch (TransientSmsException $e) {
            // mNotify's DELETE /scheduled/{id} endpoint is currently
            // returning HTTP 500 provider-side. Fall back to defusing:
            // reschedule the job far into the future so it can never
            // fire, then treat it as cancelled.
            if (! isset($defuseAttempted) || ! $defuseAttempted) {
                try {
                    if ($this->attemptDefusal($sms, $delivery, $jobId)) {
                        return;
                    }
                } catch (TransientSmsException $defuseException) {
                    $e = $defuseException;
                }
            }

            $this->createPendingCancel($delivery, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Defuse a remote SMS by rescheduling it to 2099-12-31 07:00 with
     * an innocuous body — mNotify's DELETE endpoint is unreliable, and
     * credits are only charged on dispatch, so a dormant year-2099 job
     * costs nothing and can never reach a member.
     *
     * Returns true when the remote job was defused (row is marked
     * cancelled_remote), false on permanent refusal.
     *
     * @throws TransientSmsException when the failure is retry-worthy
     */
    protected function attemptDefusal(MnotifySmsService $sms, ScheduledSmsDelivery $delivery, string $jobId): bool
    {
        if ($sms->defuseScheduled($jobId)) {
            $delivery->markCancelledRemote();
            $delivery->update(['error_message' => 'Defused: rescheduled to 2099 (mNotify DELETE endpoint unavailable)']);
            Log::info("Defused remote SMS job #{$jobId} via far-future reschedule");

            return true;
        }

        return false;
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
