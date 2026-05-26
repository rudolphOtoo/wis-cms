<?php

namespace App\Jobs;

use App\Mail\BroadcastMessage;
use App\Models\MessageRecipient;
use App\Services\ArkeselSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBroadcastMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $recipientId) {}

    public function handle(ArkeselSmsService $sms): void
    {
        $recipient = MessageRecipient::with(['message.sender', 'member'])->find($this->recipientId);
        if (! $recipient) {
            return;
        }

        $message = $recipient->message;
        $branchName = 'Wesleyan International Society';
        $channel = $message->channel;

        $attempted = false;   // did we try to send on any channel?
        $failures = [];       // human-readable reasons

        try {
            // --- EMAIL ---
            if (in_array($channel, ['email', 'both'])) {
                if ($recipient->email) {
                    $attempted = true;
                    Mail::to($recipient->email)->send(new BroadcastMessage(
                        subjectLine: $message->subject ?? 'Church Announcement',
                        messageBody: $message->body,
                        recipientName: $recipient->member?->full_name ?? 'Member',
                        branchName: $branchName,
                    ));
                } elseif ($channel === 'email') {
                    $failures[] = 'No email address on file';
                }
            }

            // --- SMS (Arkesel) ---
            if (in_array($channel, ['sms', 'both'])) {
                if ($recipient->phone) {
                    $attempted = true;
                    $sent = $sms->send($recipient->phone, $message->body);
                    if (! $sent) {
                        $failures[] = 'SMS provider did not accept the message';
                    }
                } elseif ($channel === 'sms') {
                    $failures[] = 'No phone number on file';
                }
            }

            // --- Honest delivery status ---
            if (! $attempted) {
                $recipient->update([
                    'delivery_status' => 'failed',
                    'failure_reason' => $failures ? implode('; ', $failures) : 'No deliverable channel for this recipient',
                ]);
            } elseif ($failures) {
                $recipient->update([
                    'delivery_status' => 'failed',
                    'failure_reason' => implode('; ', $failures),
                ]);
            } else {
                $recipient->update([
                    'delivery_status' => 'delivered',
                    'delivered_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            $recipient->update([
                'delivery_status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);
            Log::error('Message delivery failed: '.$e->getMessage());
        }
    }
}
