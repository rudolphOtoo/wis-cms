<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $messages = Message::with('sender')
            ->withCount([
                'recipients',
                'recipients as delivered_count' => fn ($q) => $q->where('delivery_status', 'delivered'),
                'recipients as failed_count' => fn ($q) => $q->where('delivery_status', 'failed'),
            ])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => collect($messages->items())->map(fn ($m) => [
                'id' => $m->id,
                'subject' => $m->subject,
                'body_preview' => mb_substr($m->body, 0, 140).(mb_strlen($m->body) > 140 ? '…' : ''),
                'channel' => $m->channel,
                'status' => $m->status,
                'recipient_group' => $m->recipient_group,
                'sender' => $m->sender?->name,
                'total_recipients' => $m->recipients_count,
                'delivered_count' => $m->delivered_count,
                'failed_count' => $m->failed_count,
                'sent_at' => $m->sent_at?->diffForHumans(),
                'created_at' => $m->created_at->format('Y-m-d H:i'),
            ]),
            'meta' => [
                'total' => $messages->total(),
                'per_page' => $messages->perPage(),
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $message = Message::with(['sender', 'recipients.member'])->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'subject' => $message->subject,
                'body' => $message->body,
                'channel' => $message->channel,
                'status' => $message->status,
                'recipient_group' => $message->recipient_group,
                'sender' => $message->sender?->name,
                'sent_at' => $message->sent_at?->format('Y-m-d H:i'),
                'created_at' => $message->created_at->format('Y-m-d H:i'),
                'total_recipients' => $message->total_recipients,
                'delivered_count' => $message->delivered_count,
                'failed_count' => $message->failed_count,
                'recipients' => $message->recipients->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->member?->full_name ?? '—',
                    'email' => $r->email,
                    'phone' => $r->phone,
                    'delivery_status' => $r->delivery_status,
                    'delivered_at' => $r->delivered_at?->diffForHumans(),
                    'failure_reason' => $r->failure_reason,
                ]),
            ],
        ]);
    }

    public function recipientCount(Request $request): JsonResponse
    {
        $request->validate([
            'recipient_group' => ['required', 'in:all,department,gender,status,individual'],
            'department_id' => ['nullable', 'uuid'],
            'gender' => ['nullable', 'in:male,female'],
            'status' => ['nullable', 'in:active,inactive,transferred,deceased'],
            'member_ids' => ['nullable', 'array'],
            'channel' => ['required', 'in:sms,email,both'],
        ]);

        $count = $this->resolveRecipients($request)->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'channel' => ['required', 'in:sms,email,both'],
            'recipient_group' => ['required', 'in:all,department,gender,status,individual'],
            'department_id' => ['nullable', 'uuid'],
            'gender' => ['nullable', 'in:male,female'],
            'status' => ['nullable', 'in:active,inactive,transferred,deceased'],
            'member_ids' => ['nullable', 'array'],
        ]);

        $recipients = $this->resolveRecipients($request)->get();

        if ($recipients->isEmpty()) {
            return response()->json(['message' => 'No recipients match the selected criteria.'], 422);
        }

        // PERF FIX: pre-generate UUIDs here so we can build a single bulk
        // INSERT instead of N individual Eloquent creates. MessageRecipient
        // uses HasUuids, which only runs on create() — insert() bypasses it,
        // so UUIDs must be provided explicitly.
        $now = now();
        $rows = $recipients->map(fn ($m) => [
            'id' => (string) Str::uuid(),
            'message_id' => null,   // filled after Message::create()
            'member_id' => $m->id,
            'phone' => $m->phone,
            'email' => $m->email,
            'delivery_status' => 'pending',
            'delivery_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // Jobs are dispatched OUTSIDE the transaction so that a rollback
        // never leaves orphaned jobs on the queue pointing at non-existent rows.
        [$message, $recipientIds] = DB::transaction(function () use ($request, $rows, $now) {
            $msg = Message::create([
                'branch_id' => $request->user()->branch_id,
                'sender_id' => $request->user()->id,
                'subject' => $request->input('subject'),
                'body' => $request->input('body'),
                'channel' => $request->input('channel'),
                'status' => 'sending',
                'recipient_group' => $request->input('recipient_group'),
                'department_id' => $request->input('department_id'),
                'sent_at' => $now,
            ]);

            // Stamp message_id now that we have it, then bulk-insert all rows
            // in a single query (1 INSERT vs N INSERTs for large broadcasts).
            $stamped = array_map(fn ($r) => [...$r, 'message_id' => $msg->id], $rows);
            MessageRecipient::insert($stamped);

            $msg->update(['status' => 'sent']);

            return [$msg, array_column($stamped, 'id')];
        });

        // Dispatch one job per recipient after the transaction has committed.
        foreach ($recipientIds as $rid) {
            SendBroadcastMessageJob::dispatch($rid);
        }

        activity()->causedBy($request->user())
            ->performedOn($message)
            ->log("Sent {$request->input('channel')} message to {$recipients->count()} recipients");

        return response()->json([
            'message' => "Message sent to {$recipients->count()} recipients.",
            'data' => ['id' => $message->id],
        ], 201);
    }

    public function stats(): JsonResponse
    {
        $now = now();
        $branchId = request()->user()->branch_id;

        $messageStats = Message::where('branch_id', $branchId)
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'sent') AS total_sent,
                COUNT(*) FILTER (
                    WHERE EXTRACT(MONTH FROM created_at) = ?
                      AND EXTRACT(YEAR FROM created_at) = ?
                ) AS this_month
            ", [$now->month, $now->year])
            ->first();

        $totalRecipients = MessageRecipient::whereHas('message', fn ($q) => $q->where('branch_id', $branchId)
        )->count();

        return response()->json([
            'data' => [
                'total_sent' => (int) $messageStats->total_sent,
                'this_month' => (int) $messageStats->this_month,
                'total_recipients' => $totalRecipients,
            ],
        ]);
    }

    /**
     * Resolve a Member query based on recipient_group choice.
     *
     * SMELL-04 FIX: The original 'both' channel branch had a broken
     * orWhere clause:
     *
     *   $query->where(function ($q) {
     *       $q->whereNotNull('email')->where('email', '!=', '')
     *           ->orWhereNotNull('phone')->where('phone', '!=', '');
     *   });
     *
     * The trailing `->where('phone', '!=', '')` was chained OUTSIDE the
     * closure as a top-level AND, not inside the OR. This produced the
     * SQL: `(email IS NOT NULL AND email != '') OR phone IS NOT NULL`
     * AND `phone != ''`, silently dropping members with no phone but a
     * valid email from "both" broadcasts.
     *
     * Fix: each OR branch is its own nested closure.
     */
    protected function resolveRecipients(Request $request): Builder
    {
        $query = Member::query();
        $channel = $request->input('channel');

        // ── Contact-info filter ───────────────────────────────────────────────
        match ($channel) {
            'email' => $query->whereNotNull('email')->where('email', '!=', ''),
            'sms' => $query->whereNotNull('phone')->where('phone', '!=', ''),
            // SMELL-04 FIX: both OR branches are wrapped in separate closures.
            default => $query->where(function ($q) {
                $q->where(function ($email) {
                    $email->whereNotNull('email')->where('email', '!=', '');
                })->orWhere(function ($phone) {
                    $phone->whereNotNull('phone')->where('phone', '!=', '');
                });
            }),
        };

        // ── Audience filter ───────────────────────────────────────────────────
        switch ($request->input('recipient_group')) {
            case 'all':
                $query->where('status', 'active');
                break;

            case 'department':
                if ($deptId = $request->input('department_id')) {
                    $query->whereHas('departments', fn ($q) => $q->where('departments.id', $deptId));
                }
                break;

            case 'gender':
                $query->where('status', 'active');
                if ($gender = $request->input('gender')) {
                    $query->where('gender', $gender);
                }
                break;

            case 'status':
                if ($status = $request->input('status')) {
                    $query->where('status', $status);
                }
                break;

            case 'individual':
                $query->whereIn('id', $request->input('member_ids', []));
                break;
        }

        return $query;
    }
}
