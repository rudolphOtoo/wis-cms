<?php

namespace App\Console\Commands;

use App\Jobs\SendBroadcastMessageJob;
use App\Models\BirthdayMessageLog;
use App\Models\BirthdayMessageSettings;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendBirthdayGreetings extends Command
{
    protected $signature = 'birthdays:send {--date= : Override "today" as YYYY-MM-DD (testing)}';

    protected $description = 'Send birthday greetings to members whose birthday is today';

    public function handle(): int
    {
        if (! config('church.birthday.enabled')) {
            $this->info('Birthday greetings are disabled (config/church.php).');

            return self::SUCCESS;
        }

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now();

        $channel = config('church.birthday.channel', 'sms');
        $subject = config('church.birthday.subject', 'Happy Birthday!');

        // Find members with a birthday today (regardless of phone presence).
        // We decide per-member whether to send, skip, or log no_phone — so we
        // can keep an audit trail of members we couldn't reach.
        $members = Member::query()
            ->where('status', 'active')
            ->whereNotNull('date_of_birth')
            ->whereRaw('EXTRACT(MONTH FROM date_of_birth) = ?', [$date->month])
            ->whereRaw('EXTRACT(DAY FROM date_of_birth) = ?', [$date->day])
            ->get();

        if ($members->isEmpty()) {
            $this->info('No birthdays today.');

            return self::SUCCESS;
        }

        $sentCount = 0;
        $skippedNoPhone = 0;
        $skippedAlreadySent = 0;
        $failed = 0;

        foreach ($members as $member) {
            // Idempotency: don't send twice on the same day.
            // Safe to re-run the command (e.g. after a transient failure
            // earlier in the day) without spamming members.
            if (BirthdayMessageLog::memberSentToday($member->id)) {
                $skippedAlreadySent++;

                continue;
            }

            // No phone → log it, skip silently. Admin can review the log
            // and reach out manually if needed.
            if (empty($member->phone)) {
                BirthdayMessageLog::create([
                    'branch_id' => $member->branch_id,
                    'member_id' => $member->id,
                    'sent_at' => now(),
                    'status' => BirthdayMessageLog::STATUS_NO_PHONE,
                ]);
                $skippedNoPhone++;

                continue;
            }

            try {
                // Template comes from the per-branch BirthdayMessageSettings
                // row (created on first access with a sensible default).
                $settings = BirthdayMessageSettings::forBranch($member->branch_id);
                $body = $settings->render(
                    $member,
                    config('church.name', 'Wesleyan International Society')
                );

                $message = Message::create([
                    'branch_id' => $member->branch_id,
                    'sender_id' => null, // system-generated
                    'subject' => $subject,
                    'body' => $body,
                    'channel' => $channel,
                    'status' => 'sent',
                    'recipient_group' => 'birthday',
                    'sent_at' => now(),
                ]);

                $recipient = MessageRecipient::create([
                    'message_id' => $message->id,
                    'member_id' => $member->id,
                    'phone' => $member->phone,
                    'email' => $member->email,
                    'delivery_status' => 'pending',
                ]);

                SendBroadcastMessageJob::dispatch($recipient->id);

                BirthdayMessageLog::create([
                    'branch_id' => $member->branch_id,
                    'member_id' => $member->id,
                    'sent_at' => now(),
                    'status' => BirthdayMessageLog::STATUS_SENT,
                    'phone_used' => $member->phone,
                    'message_body' => $body,
                ]);

                $sentCount++;
            } catch (Throwable $e) {
                // The retry-enabled SendBroadcastMessageJob handles transient
                // SMS failures itself. If we reach here, something more
                // fundamental went wrong (DB error, etc.) — log and continue.
                BirthdayMessageLog::create([
                    'branch_id' => $member->branch_id,
                    'member_id' => $member->id,
                    'sent_at' => now(),
                    'status' => BirthdayMessageLog::STATUS_FAILED,
                    'phone_used' => $member->phone,
                    'error_message' => $e->getMessage(),
                ]);
                $failed++;

                Log::error('Birthday greeting failed for member '.$member->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Birthday greetings dispatched to {$sentCount} member(s) for {$date->toDateString()}.");
        $this->info("Birthday greetings dispatched to {$sentCount} member(s).");

        if ($skippedNoPhone > 0) {
            $this->warn("Skipped {$skippedNoPhone} member(s) without a phone number.");
        }
        if ($skippedAlreadySent > 0) {
            $this->line("Skipped {$skippedAlreadySent} member(s) already sent today (idempotent).");
        }
        if ($failed > 0) {
            $this->error("Failed for {$failed} member(s) — see log table for details.");
        }

        return self::SUCCESS;
    }
}
