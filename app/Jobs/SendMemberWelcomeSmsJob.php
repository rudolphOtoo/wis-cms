<?php

namespace App\Jobs;

use App\Models\Member;
use App\Services\MnotifySmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMemberWelcomeSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 60, 300]; // Retry at 10s, 60s, 5min
    }

    /**
     * Create a new job instance.
     *
     * @param  string  $memberId  The member to send SMS to
     * @param  string|null  $cellName  Optional cell name for personalization
     */
    public function __construct(
        public string $memberId,
        public ?string $cellName = null,
    ) {}

    public function handle(MnotifySmsService $sms): void
    {
        $member = Member::findOrFail($this->memberId);

        if (! $member->phone) {
            Log::info("Member {$member->id} has no phone; skipping welcome SMS");

            return;
        }

        $message = $this->buildWelcomeMessage($member);

        try {
            $sms->send($member->phone, $message);
            Log::info('Welcome SMS sent', [
                'member_id' => $member->id,
                'phone' => $member->phone,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send welcome SMS', [
                'member_id' => $member->id,
                'phone' => $member->phone,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }

    protected function buildWelcomeMessage(Member $member): string
    {
        $churchName = config('church.name', 'Our Church');
        $cellInfo = $this->cellName ? " in {$this->cellName}" : '';

        return "Welcome to {$churchName}, {$member->first_name}!{$cellInfo} "
            ."We're excited to have you join our community. "
            .'Please look for a call or message from us soon.';
    }
}
