<?php

namespace App\Jobs;

use App\Mail\BroadcastMessage;
use App\Models\MessageRecipient;
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

    public function handle(): void
    {
        $recipient = MessageRecipient::with(['message.sender', 'member'])->find($this->recipientId);

        if (! $recipient) {
            return;
        }

        $message = $recipient->message;
        $branchName = $message->sender?->name ? 'Wesleyan International Society' : 'WIS-CMS';

        try {
            if (in_array($message->channel, ['email', 'both']) && $recipient->email) {
                Mail::to($recipient->email)->send(new BroadcastMessage(
                    subjectLine: $message->subject ?? 'Church Announcement',
                    messageBody: $message->body,
                    recipientName: $recipient->member?->full_name ?? 'Member',
                    branchName: $branchName,
                ));
            }

            // SMS dispatch placeholder — wire Arkesel here later
            if (in_array($message->channel, ['sms', 'both']) && $recipient->phone) {
                Log::info("SMS would send to {$recipient->phone}: {$message->body}");
                // TODO: Arkesel API call goes here
            }

            $recipient->update([
                'delivery_status' => 'delivered',
                'delivered_at' => now(),
            ]);

        } catch (\Throwable $e) {
            $recipient->update([
                'delivery_status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);
            Log::error('Message delivery failed: '.$e->getMessage());
        }
    }
}
