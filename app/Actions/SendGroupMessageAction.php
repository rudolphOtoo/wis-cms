<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendBroadcastMessageJob;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Broadcast a message to a resolved set of members.
 *
 * Single home for the group-messaging pipeline that used to be
 * copy-pasted across CellController, DepartmentController and
 * MessageController: channel contact filtering, message persistence,
 * bulk recipient insert, status flip, queue dispatch and activity log.
 *
 * Recipients are written with ONE bulk INSERT (UUIDs are pre-generated
 * because MessageRecipient::create() is what would otherwise mint them
 * per row), jobs are dispatched only after the transaction commits, and
 * dispatching happens outside the transaction so a rollback can never
 * leave orphaned jobs pointing at rows that no longer exist.
 */
class SendGroupMessageAction
{
    /**
     * Narrow a member query to members reachable on the given channel.
     *
     * 'both' keeps members that have either contact route; each OR arm
     * is its own nested closure so the predicates cannot leak across the
     * OR boundary.
     */
    public function contacts(Builder|Relation $query, string $channel): Builder|Relation
    {
        return $query->where(function (Builder $q) use ($channel): void {
            match ($channel) {
                'email' => $q->whereNotNull('email')->where('email', '!=', ''),
                'sms' => $q->whereNotNull('phone')->where('phone', '!=', ''),
                default => $q->where(function (Builder $email): void {
                    $email->whereNotNull('email')->where('email', '!=', '');
                })->orWhere(function (Builder $phone): void {
                    $phone->whereNotNull('phone')->where('phone', '!=', '');
                }),
            };
        });
    }

    /**
     * Persist the message + recipients, then queue one send job per
     * recipient. Returns the message with status already flipped to
     * 'sent' (the flip happens inside the transaction, matching the
     * pipeline's historical behaviour).
     *
     * @param  Collection<int, Member>  $recipients
     * @param  array<string, mixed>  $messageAttributes  Message payload
     *                                                   minus branch_id/sender_id/status/sent_at, which this action owns.
     */
    public function send(
        User $sender,
        Collection $recipients,
        array $messageAttributes,
        string $activityLog,
    ): Message {
        $now = now();

        // Pre-generate UUIDs so recipients land with a single bulk INSERT
        // instead of one Eloquent create() per recipient.
        $rows = $recipients->map(fn ($member) => [
            'id' => (string) Str::uuid(),
            'message_id' => null,
            'member_id' => $member->id,
            'phone' => $member->phone,
            'email' => $member->email,
            'delivery_status' => 'pending',
            'delivery_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        [$message, $recipientIds] = DB::transaction(function () use ($sender, $messageAttributes, $rows, $now): array {
            $message = Message::create(array_merge($messageAttributes, [
                'branch_id' => $sender->branch_id,
                'sender_id' => $sender->id,
                'status' => 'sending',
                'sent_at' => $now,
            ]));

            $stamped = array_map(fn ($row) => [...$row, 'message_id' => $message->id], $rows);
            MessageRecipient::insert($stamped);

            $message->update(['status' => 'sent']);

            return [$message, array_column($stamped, 'id')];
        });

        foreach ($recipientIds as $recipientId) {
            SendBroadcastMessageJob::dispatch($recipientId);
        }

        activity()->causedBy($sender)
            ->performedOn($message)
            ->log($activityLog);

        return $message;
    }
}
