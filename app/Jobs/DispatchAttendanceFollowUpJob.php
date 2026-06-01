<?php

namespace App\Jobs;

use App\Models\AttendanceSession;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Services\FollowUpTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends the automated post-meeting follow-up SMS for ONE attendance
 * session. Triggered by ProcessPendingAttendanceFollowUps scheduler
 * command (every 15 min), which finds sessions where:
 *
 *   - follow_up_status = 'not_sent'
 *   - branch.follow_up_enabled = true
 *   - now() >= session.created_at + (branch.follow_up_delay_hours hours)
 *
 * For each such session, this job:
 *   1. Locks the session row, flips status -> 'sending' (prevents double-send)
 *   2. Loads attendance_records grouped by presence
 *   3. Creates one Message row per group (present, absent) with rendered
 *      body for the recipient's name baked in via MessageRecipient
 *   4. Dispatches SendBroadcastMessageJob per recipient (reuses existing
 *      delivery pipeline)
 *   5. Marks session 'sent' on success, 'failed' on exception
 */
class DispatchAttendanceFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Don't auto-retry; double-send is worse than failed-send

    public function __construct(public string $sessionId) {}

    public function handle(): void
    {
        $session = AttendanceSession::with(['cell', 'department', 'branch', 'records.member'])
            ->find($this->sessionId);

        if (! $session) {
            Log::warning("FollowUp: session {$this->sessionId} not found");

            return;
        }

        $branch = $session->branch;
        if (! $branch || ! $branch->follow_up_enabled) {
            $session->update(['follow_up_status' => 'disabled']);

            return;
        }

        if ($session->follow_up_status !== 'not_sent') {
            // Already processed (or in flight) — guard against double-dispatch
            return;
        }

        // Lock + flip status atomically
        $acquired = DB::transaction(function () use ($session) {
            $fresh = AttendanceSession::lockForUpdate()->find($session->id);
            if ($fresh->follow_up_status !== 'not_sent') {
                return false;
            }
            $fresh->update(['follow_up_status' => 'sending']);

            return true;
        });

        if (! $acquired) {
            return; // Another worker grabbed it
        }

        try {
            // Records linked to actual Members (skip child rows for cell/dept meetings)
            $records = $session->records->filter(fn ($r) => $r->member_id && $r->member);

            $present = $records->where('is_present', true)->pluck('member')->unique('id');
            $absent = $records->where('is_present', false)->pluck('member')->unique('id');

            $presentSent = $this->dispatchGroup($session, $present, $branch->follow_up_present_template, 'present');
            $absentSent = $this->dispatchGroup($session, $absent, $branch->follow_up_absent_template, 'absent');

            $session->update([
                'follow_up_status' => 'sent',
                'follow_up_sent_at' => now(),
            ]);

            activity()->performedOn($session)->log(
                "Follow-up dispatched: {$presentSent} present + {$absentSent} absent"
            );
        } catch (\Throwable $e) {
            $session->update(['follow_up_status' => 'failed']);
            Log::error("FollowUp dispatch failed for session {$session->id}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a Message + MessageRecipient rows for a group (present or absent)
     * and dispatch the existing SendBroadcastMessageJob for each.
     * Returns the count actually dispatched (skips members with no phone/email
     * since SendBroadcastMessageJob would just fail those anyway).
     */
    protected function dispatchGroup(AttendanceSession $session, $members, ?string $template, string $groupLabel): int
    {
        if (! $template || $members->isEmpty()) {
            return 0;
        }

        // Filter to members with at least one deliverable channel (phone or email)
        $deliverable = $members->filter(fn ($m) => $m->phone || $m->email);
        if ($deliverable->isEmpty()) {
            return 0;
        }

        $message = Message::create([
            'branch_id' => $session->branch_id,
            'sender_id' => $session->recorded_by, // The cell leader who took attendance
            'subject' => null,
            'body' => '[Per-recipient body rendered via template]',
            'channel' => 'sms', // Council asked for SMS; if a member has no phone, SendBroadcastMessageJob will mark failed
            'status' => 'sending',
            'recipient_group' => $session->cell_id ? 'cell' : 'department',
            'cell_id' => $session->cell_id,
            'department_id' => $session->department_id,
            'sent_at' => now(),
        ]);

        foreach ($deliverable as $member) {
            $renderedBody = FollowUpTemplateRenderer::render($template, $member, $session);

            // Override the message body PER RECIPIENT by storing the
            // rendered string on the recipient row. We pass it to the
            // delivery pipeline via a small extension below.
            $recipient = MessageRecipient::create([
                'message_id' => $message->id,
                'member_id' => $member->id,
                'phone' => $member->phone,
                'email' => $member->email,
                'rendered_body' => $renderedBody,
                'delivery_status' => 'pending',
            ]);

            SendBroadcastMessageJob::dispatch($recipient->id);
        }

        $message->update(['status' => 'sent']);

        return $deliverable->count();
    }
}
