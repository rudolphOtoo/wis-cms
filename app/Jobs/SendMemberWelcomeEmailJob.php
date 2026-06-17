<?php

namespace App\Jobs;

use App\Mail\MemberWelcomeEmail;
use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendMemberWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function __construct(public string $memberId) {}

    public function handle(): void
    {
        $member = Member::findOrFail($this->memberId);

        if (! $member->email) {
            Log::info("Member {$member->id} has no email; skipping welcome email");

            return;
        }

        try {
            Mail::to($member->email)->queue(new MemberWelcomeEmail($member));
            Log::info('Welcome email queued', [
                'member_id' => $member->id,
                'email' => $member->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to queue welcome email', [
                'member_id' => $member->id,
                'email' => $member->email,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }
}
