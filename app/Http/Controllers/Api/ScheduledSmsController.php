<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScheduledSmsDelivery;
use App\Services\RecurringSmsScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-delivery control over SMS dispatches that are already scheduled.
 *
 * The reminder/birthday settings screens toggle a whole automation, which
 * cancels every future message for that source. Admins also need to drop a
 * single dispatch (a member reported a wrong number, a duplicate slipped
 * through, a message should no longer go out). Those messages live on
 * mNotify's cloud, so cancelling locally is not enough: unless a
 * DELETE /scheduled/{id} is issued, the provider still fires and the
 * member receives a ghost SMS.
 *
 * Every action here therefore round-trips to mNotify before responding, so
 * the UI can state definitively that the message is cancelled in the cloud
 * rather than merely hidden locally.
 */
class ScheduledSmsController extends Controller
{
    /**
     * GET /api/sms/scheduled
     * Upcoming dispatches for the admin's branch, with an explicit
     * cloud-state flag per row.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'days' => ['nullable', 'integer', 'between:1,60'],
            'source_type' => ['nullable', 'string', 'in:reminder,birthday'],
        ]);

        $days = (int) ($validated['days'] ?? 14);

        $rows = ScheduledSmsDelivery::query()
            ->where('branch_id', $request->user()->branch_id)
            ->where('scheduled_at', '>=', now())
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['source_type'] ?? null, fn ($q, $s) => $q->where('source_type', $s))
            ->where('scheduled_at', '<', now()->addDays($days))
            ->orderBy('scheduled_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ScheduledSmsDelivery $d) => $this->present($d))->values(),
            'meta' => [
                'days' => $days,
                'total' => $rows->count(),
                'cancellable' => $rows->filter->isCancellable()->count(),
                'not_on_cloud' => $rows->filter(
                    fn ($d) => $d->isCancellable() && ! $d->mnotify_job_id
                )->count(),
            ],
        ]);
    }

    /**
     * POST /api/sms/scheduled/{delivery}/cancel
     *
     * Cancel one dispatch. Issues DELETE /scheduled/{id} against mNotify
     * (falling back to a far-future defusal when the provider's DELETE is
     * unavailable) and only then reports the outcome, so the UI never
     * claims "Cancelled" while a live job remains on the provider.
     */
    public function cancel(Request $request, ScheduledSmsDelivery $delivery): JsonResponse
    {
        $this->authorizeBranch($request, $delivery);

        if (! $delivery->isCancellable()) {
            return response()->json([
                'message' => 'This dispatch can no longer be cancelled (status: '.$delivery->status.').',
                'data' => $this->present($delivery->fresh()),
            ], 409);
        }

        // Never reached the provider (pending_api with no job ID) — nothing
        // to delete in the cloud, so cancel locally.
        if (! $delivery->mnotify_job_id) {
            $delivery->markCancelled();

            return response()->json([
                'message' => 'Cancelled. This message was never uploaded to mNotify, so nothing was sent from the cloud.',
                'cloud_cancelled' => false,
                'data' => $this->present($delivery->fresh()),
            ]);
        }

        // Round-trips to mNotify. Failures are logged and queued for retry
        // rather than aborting, and the response still reflects the true
        // local state.
        app(RecurringSmsScheduler::class)->cancelDeliveryOnMnotify($delivery);

        $delivery->refresh();
        $cloudCancelled = $delivery->status === ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE;

        if ($cloudCancelled) {
            return response()->json([
                'message' => 'Cancelled on mNotify. The message will not be delivered.',
                'cloud_cancelled' => true,
                'data' => $this->present($delivery),
            ]);
        }

        // Honest reporting matters here: a non-confirmed cancel may still be
        // live on mNotify, and telling an admin otherwise would let them
        // assume the member is safe. CancelScheduledSmsJob has already
        // queued a retry, which will re-attempt the DELETE and then defuse
        // the job to 2099 if the provider keeps refusing.
        return response()->json([
            'message' => 'mNotify has not confirmed the cancellation yet. '
                .'The withdraw has been queued for automatic retry and the job will be '
                .'defused (parked in 2099) if the provider keeps refusing — but until that '
                .'succeeds, treat this message as possibly still active in the cloud.',
            'cloud_cancelled' => false,
            'retry_queued' => true,
            'data' => $this->present($delivery),
        ], 202);
    }

    protected function authorizeBranch(Request $request, ScheduledSmsDelivery $delivery): void
    {
        abort_unless(
            $delivery->branch_id === $request->user()->branch_id,
            403,
            'This dispatch belongs to another branch.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(ScheduledSmsDelivery $delivery): array
    {
        $onCloud = $delivery->status === ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE
            || $delivery->status === ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE;

        return [
            'id' => $delivery->id,
            'source_type' => $delivery->source_type,
            'phone' => $delivery->phone,
            'message_body' => $delivery->message_body,
            'scheduled_at' => $delivery->scheduled_at?->toIso8601String(),
            'status' => $delivery->status,
            'status_label' => $this->statusLabel($delivery->status),
            'is_cancellable' => $delivery->isCancellable(),
            'on_cloud' => $onCloud,
            'mnotify_job_id' => $delivery->mnotify_job_id,
            'error_message' => $delivery->error_message,
        ];
    }

    /**
     * Admin-facing wording. An explicit cancel is reported as "Cancelled",
     * never "Paused" — pausing an automation is a settings-level concept
     * and conflating the two hides whether a message was really withdrawn.
     */
    protected function statusLabel(string $status): string
    {
        return match ($status) {
            ScheduledSmsDelivery::STATUS_PENDING_API => 'Awaiting upload',
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE => 'Scheduled on mNotify',
            ScheduledSmsDelivery::STATUS_DISPATCHED => 'Sent',
            ScheduledSmsDelivery::STATUS_CANCELLED => 'Cancelled',
            ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE => 'Cancelled on mNotify',
            ScheduledSmsDelivery::STATUS_FAILED => 'Failed',
            ScheduledSmsDelivery::STATUS_FAILED_PROVIDER => 'Failed (provider)',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
