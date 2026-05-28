<?php

namespace App\Console\Commands;

use App\Jobs\SendBroadcastMessageJob;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

        $channel = config('church.birthday.channel', 'both');
        $template = config('church.birthday.message');
        $subject = config('church.birthday.subject', 'Happy Birthday!');
        $church = config('church.name', 'Wesleyan International Society');

        $members = Member::query()
            ->where('status', 'active')
            ->whereNotNull('date_of_birth')
            ->whereRaw('EXTRACT(MONTH FROM date_of_birth) = ?', [$date->month])
            ->whereRaw('EXTRACT(DAY FROM date_of_birth) = ?', [$date->day])
            ->where(function ($q) {
                $q->whereNotNull('phone')->orWhereNotNull('email');
            })
            ->get();

        if ($members->isEmpty()) {
            $this->info('No birthdays today.');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($members as $member) {
            $body = str_replace(
                ['{first_name}', '{church}'],
                [$member->first_name, $church],
                $template
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

            $count++;
        }

        Log::info("Birthday greetings dispatched to {$count} member(s) for {$date->toDateString()}.");
        $this->info("Birthday greetings dispatched to {$count} member(s).");

        return self::SUCCESS;
    }
}
