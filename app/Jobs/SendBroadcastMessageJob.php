<?php

namespace App\Jobs;

use App\Exceptions\TransientSmsException;
use App\Mail\BroadcastMessage;
use App\Models\MessageRecipient;
use App\Services\MnotifySmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a single broadcast message to a single recipient across
 * configured channels (email, SMS, or both).
 *
 * IDEMPOTENCY
 * Tracks per-channel success via email_sent_at and sms_sent_at on
 * the recipient row. On retry, channels with a non-null timestamp
 * are skipped - prevents duplicate emails when SMS fails transiently.
 *
 * RETRY BEHAVIOUR
 * Configured for 3 attempts with exponential backoff (1m, 5m, 15m).
 * TransientSmsException (HTTP 5xx, network errors) propagates and
 * triggers the next retry. Permanent failures (false return,
 * Mail throws) record the failure reason without further retries.
 *
 * Note: with QUEUE_CONNECTION=sync (dev default), the "retries"
 * execute inline with no real delay. In production with database
 * or redis queue, the backoff is honoured.
 */
class SendBroadcastMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Total attempts before the job is marked permanently failed.
     */
    public int $tries = 3;

    /**
     * Backoff in seconds between attempts: 1 min, 5 min, 15 min.
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public string $recipientId) {}

    public function handle(MnotifySmsService $sms): void
    {
        $recipient = MessageRecipient::with(['message.sender', 'member'])->find($this->recipientId);
        if (! $recipient) {
            return;
        }

        // Increment retry counter for observability
        $recipient->increment('delivery_attempts');

        $message = $recipient->message;
        // Personalised per-recipient body wins; falls back to shared
        // message body for legacy broadcasts.
        $body = $recipient->rendered_body ?: $message->body;
        $branchName = 'Wesleyan International Society';
        $channel = $message->channel;

        $failures = [];
        $attempted = false;

        // --- EMAIL (idempotent: skip if already sent on prior attempt) ---
        if (in_array($channel, ['email', 'both']) && ! $recipient->email_sent_at) {
            if ($recipient->email) {
                $attempted = true;
                try {
                    Mail::to($recipient->email)->send(new BroadcastMessage(
                        subjectLine: $message->subject ?? 'Church Announcement',
                        messageBody: $body,
                        recipientName: $recipient->member?->full_name ?? 'Member',
                        branchName: $branchName,
                    ));
                    $recipient->update(['email_sent_at' => now()]);
                } catch (\Throwable $e) {
                    // Email errors are treated as permanent (we don't
                    // distinguish transient vs permanent for Mail; the
                    // underlying transport already retries internally).
                    Log::error('Email delivery failed for recipient '.$recipient->id.': '.$e->getMessage());
                    $failures[] = 'Email: '.$e->getMessage();
                }
            } elseif ($channel === 'email') {
                $failures[] = 'No email address on file';
            }
        }

        // --- SMS (idempotent: skip if already sent on prior attempt) ---
        if (in_array($channel, ['sms', 'both']) && ! $recipient->sms_sent_at) {
            if ($recipient->phone) {
                $attempted = true;
                // TransientSmsException is INTENTIONALLY NOT CAUGHT here -
                // it propagates to the queue worker which triggers retry.
                $sent = $sms->send($recipient->phone, $body);
                if ($sent) {
                    $recipient->update(['sms_sent_at' => now()]);
                } else {
                    $failures[] = 'SMS provider did not accept the message';
                }
            } elseif ($channel === 'sms') {
                $failures[] = 'No phone number on file';
            }
        }

        // --- Reconcile overall delivery_status ---
        //
        // Vocabulary:
        //   "needed"  - the message channel includes this lane
        //   "sent"    - this lane has a real timestamp (true delivery)
        //   "skipped" - lane is needed but recipient has no address;
        //               not a delivery, but not a failure either when
        //               another lane succeeded
        //
        // Outcomes:
        //   delivered - at least one needed lane was actually sent AND
        //               every needed lane is either sent or skipped
        //   failed    - everything else (no lane sent successfully)
        $fresh = $recipient->fresh();
        $emailNeeded = in_array($channel, ['email', 'both']);
        $smsNeeded = in_array($channel, ['sms', 'both']);

        $emailSent = $fresh->email_sent_at !== null;
        $smsSent = $fresh->sms_sent_at !== null;
        $emailSkippable = $emailNeeded && empty($fresh->email);
        $smsSkippable = $smsNeeded && empty($fresh->phone);

        $somethingActuallySent = $emailSent || $smsSent;
        $emailDone = ! $emailNeeded || $emailSent || $emailSkippable;
        $smsDone = ! $smsNeeded || $smsSent || $smsSkippable;

        if ($somethingActuallySent && $emailDone && $smsDone) {
            $recipient->update([
                'delivery_status' => 'delivered',
                'delivered_at' => now(),
            ]);
        } else {
            // Either nothing sent successfully, or a needed lane is still
            // outstanding (will be retried by the queue if transient).
            $reason = $failures
                ? implode('; ', $failures)
                : 'No deliverable channel for this recipient';
            $recipient->update([
                'delivery_status' => 'failed',
                'failure_reason' => $reason,
            ]);
        }
    }
}
