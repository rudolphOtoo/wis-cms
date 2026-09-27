<?php

namespace App\Services;

use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderLog;
use App\Models\ServiceReminderSettings;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes the audit trail for reminder messages taken off the schedule.
 *
 * A cancellation has to leave two traces at once: the live dispatch must
 * disappear from the admin screen, and somebody has to be able to answer
 * "was that message withdrawn, by whom, and did mNotify actually let go
 * of it?". Deleting the row would answer neither, so the withdrawal is
 * recorded here instead.
 *
 * Only deliberate admin actions are recorded. The routine churn of a
 * configure-time resync — which retires and recreates a hundred future
 * dispatches every time a template is tweaked — is bookkeeping, not an
 * event, and logging it would bury the entries a human reads.
 *
 * A withdrawal that belongs to no reminder (a birthday greeting, say) is
 * still recorded, minus the service type and date it has no answer for.
 * The number that was texted is the fact that matters in that case, and
 * it is always kept.
 */
class ReminderCancellationLog
{
    /**
     * Record the withdrawal of a single scheduled dispatch.
     *
     * The outcome is read from the delivery's own status rather than
     * passed in, so the log cannot claim a clean cloud cancel when the
     * provider never confirmed one.
     */
    public function recordMessageWithdrawal(
        ScheduledSmsDelivery $delivery,
        ?User $actor = null,
    ): ServiceReminderLog {
        $settings = $this->settingsFor($delivery);

        return ServiceReminderLog::create([
            'branch_id' => $delivery->branch_id,
            'service_type_id' => $settings?->service_type_id,
            'member_id' => $this->memberFor($delivery)?->id,
            'sent_at' => now(),
            // The date the service is FOR, not the send slot. Derived with
            // the same rule the sender uses, so a Saturday-sent reminder
            // for Sunday's service is filed under the Sunday.
            'intended_service_date' => $settings && $delivery->scheduled_at
                ? app(RecurringSmsScheduler::class)->computeIntendedServiceDate($settings, $delivery->scheduled_at)
                : null,
            'status' => ServiceReminderLog::STATUS_CANCELLED,
            'phone_used' => $delivery->phone,
            'message_body' => $delivery->message_body,
            'detail' => $this->messageDetail($delivery),
            'cancelled_by' => $actor?->id,
        ]);
    }

    /**
     * Record the withdrawal of an entire automation.
     *
     * One row, not one per member: switching a reminder off retires every
     * future dispatch for that service type (over a hundred on a normal
     * branch) and the useful fact is "this reminder was switched off and
     * these are the messages that went with it", not the same sentence a
     * hundred times.
     */
    public function recordAutomationWithdrawal(
        ServiceReminderSettings $settings,
        int $withdrawnCount,
        string $reason,
        ?User $actor = null,
    ): ServiceReminderLog {
        return ServiceReminderLog::create([
            'branch_id' => $settings->branch_id,
            'service_type_id' => $settings->service_type_id,
            'sent_at' => now(),
            'status' => ServiceReminderLog::STATUS_CANCELLED_BATCH,
            'detail' => $this->automationDetail($withdrawnCount, $reason),
            'cancelled_by' => $actor?->id,
        ]);
    }

    /**
     * The reminder settings a dispatch belongs to, when it has any.
     */
    protected function settingsFor(ScheduledSmsDelivery $delivery): ?ServiceReminderSettings
    {
        if ($delivery->source_type !== 'reminder' || ! $delivery->source_id) {
            return null;
        }

        return ServiceReminderSettings::find($delivery->source_id);
    }

    /**
     * The member a dispatch was addressed to.
     *
     * ScheduledSmsDelivery stores the rendered phone number, not a member
     * id, so the member is resolved from the number. Birthday dispatches
     * do carry the member in source_id and are preferred, since a branch
     * can hold two members on one shared number and the wrong name in an
     * audit trail is worse than no name.
     */
    protected function memberFor(ScheduledSmsDelivery $delivery): ?Member
    {
        if ($delivery->source_type === 'birthday' && $delivery->source_id) {
            $fromSource = Member::query()
                ->where('branch_id', $delivery->branch_id)
                ->whereKey($delivery->source_id)
                ->first();

            if ($fromSource) {
                return $fromSource;
            }
        }

        if (empty($delivery->phone)) {
            return null;
        }

        return Member::query()
            ->where('branch_id', $delivery->branch_id)
            ->where('phone', $delivery->phone)
            ->orderBy('created_at')
            ->first();
    }

    protected function messageDetail(ScheduledSmsDelivery $delivery): string
    {
        return match ($delivery->status) {
            ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE => 'Withdrawn from mNotify. This member will not receive the message.',
            ScheduledSmsDelivery::STATUS_CANCELLED => 'Cancelled before upload — the message never reached mNotify, so nothing was sent from the cloud.',
            // The provider refused the delete: the withdraw is queued, but
            // until it lands the message can still fire. The log must not
            // imply otherwise.
            default => 'Withdraw requested; mNotify has not confirmed the removal. Queued for automatic retry — treat the message as possibly still active.',
        };
    }

    protected function automationDetail(int $withdrawnCount, string $reason): string
    {
        $action = $reason === 'deleted'
            ? 'Reminder deleted'
            : 'Reminder switched off';

        if ($withdrawnCount === 0) {
            return $action.' — no messages were scheduled, so none needed withdrawing.';
        }

        return $action.' — '.number_format($withdrawnCount)
            .' future '.Str::plural('message', $withdrawnCount)
            .' withdrawn from mNotify.';
    }
}
