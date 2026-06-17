<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TransientSmsException;
use App\Models\Member;
use App\Models\MemberSubmission;
use App\Models\User;
use App\Services\MnotifySmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Notifies branch administrators via SMS when a pending member
 * submission has been approved and promoted to a real Member record.
 *
 * Dispatched by MemberSubmissionController::approve() after the
 * promote() transaction commits successfully.
 *
 * TARGETING
 *   Admins are defined as users with the 'super_admin' or 'admin'
 *   role who have a non-null phone number. This ensures only reachable
 *   admins are notified.
 *
 * FAILURE ISOLATION
 *   Each admin is notified in its own try-catch block. A failed delivery
 *   to one admin does not interrupt notifications to others. Individual
 *   failures are logged at error level.
 *
 *   Transient SMS errors (network failures, mNotify 5xx) cause the
 *   entire job to be re-queued so all admins get another attempt.
 *   Accepted trade-off: admins who succeeded on attempt 1 will receive
 *   a duplicate SMS on retry. With $tries = 2 this affects at most one
 *   extra message per admin. No per-admin delivery tracking is implemented.
 *
 * RETRY SEMANTICS
 *   - $tries = 2: two total attempts.
 *   - $backoff: 30s then 5 min — long enough for mNotify to recover.
 *   - Re-throws TransientSmsException so the queue worker reschedules.
 *   - Permanent failures (false return from MnotifySmsService) are
 *     logged but do not trigger a retry for that admin.
 */
class NotifyAdminOfApprovalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Backoff in seconds between retry attempts.
     * 30s gives mNotify time to recover from a brief outage;
     * 300s (5 min) handles longer degraded windows.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function __construct(
        public readonly string $submissionId,
        public readonly string $memberId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MnotifySmsService $sms): void
    {
        $submission = MemberSubmission::find($this->submissionId);
        $member = Member::find($this->memberId);

        if ($submission === null || $member === null) {
            Log::warning('NotifyAdminOfApprovalJob: submission or member not found — aborting', [
                'submission_id' => $this->submissionId,
                'member_id' => $this->memberId,
            ]);

            return; // Models may have been purged; no point retrying.
        }

        $admins = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
            ->whereNotNull('phone')
            ->where('is_active', true)
            ->get(['id', 'name', 'phone']);

        if ($admins->isEmpty()) {
            Log::warning('NotifyAdminOfApprovalJob: no reachable admins found', [
                'submission_id' => $submission->id,
                'member_id' => $member->id,
            ]);

            return;
        }

        $message = $this->buildMessage($member, $submission);
        $transientFailed = false;

        foreach ($admins as $admin) {
            try {
                $sent = $sms->send((string) $admin->phone, $message);

                if ($sent) {
                    Log::info('NotifyAdminOfApprovalJob: admin notified', [
                        'admin_id' => $admin->id,
                        'member_id' => $member->id,
                        'submission_id' => $submission->id,
                    ]);
                } else {
                    // Permanent failure — bad number, missing API key, 4xx from
                    // mNotify. MnotifySmsService logged the detail already.
                    Log::error('NotifyAdminOfApprovalJob: permanent SMS failure for admin', [
                        'admin_id' => $admin->id,
                        'admin_phone' => $admin->phone,
                        'submission_id' => $submission->id,
                    ]);
                    // Continue loop — try the remaining admins.
                }
            } catch (TransientSmsException $e) {
                // Transient error (network failure, mNotify 5xx). Log and
                // flag for retry — but continue notifying the remaining admins
                // first so as many get the alert as possible on this attempt.
                Log::warning('NotifyAdminOfApprovalJob: transient SMS failure for admin', [
                    'admin_id' => $admin->id,
                    'admin_phone' => $admin->phone,
                    'submission_id' => $submission->id,
                    'attempt' => $this->attempts(),
                    'error' => $e->getMessage(),
                ]);

                $transientFailed = true;
                // Intentionally do NOT re-throw yet — finish the loop first.
            } catch (\Throwable $e) {
                // Unexpected error (DB issue, malformed phone, etc.).
                // Log at error level but do not let it abort the loop.
                Log::error('NotifyAdminOfApprovalJob: unexpected error for admin', [
                    'admin_id' => $admin->id,
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Re-throw after the full loop so the queue worker reschedules
        // the job. On the next attempt, all admins will receive another
        // notification. Accepted trade-off: some admins may receive a
        // duplicate SMS if they succeeded on the first attempt. In
        // practice, $tries = 2 makes duplicates rare.
        if ($transientFailed) {
            throw new TransientSmsException(
                'One or more admin SMS notifications failed transiently; job will be retried.'
            );
        }
    }

    /**
     * Build the admin alert SMS body.
     *
     * Kept concise (≤ 160 chars GSM-7) to avoid multi-part SMS charges.
     * Includes: new member name, member number (if generated), who approved.
     */
    private function buildMessage(Member $member, MemberSubmission $submission): string
    {
        $approverName = $submission->reviewedBy?->name ?? 'an admin';
        $memberNumber = $member->member_number ? " (#{$member->member_number})" : '';

        return "WIS-CMS: {$member->full_name}{$memberNumber} approved as new member. "
            ."By: {$approverName}.";
    }
}
