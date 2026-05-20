<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\Department;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $messages = Message::where('branch_id', $request->user()->branch_id)
            ->with('sender')
            ->withCount(['recipients',
                         'recipients as delivered_count' => fn($q) => $q->where('delivery_status','delivered'),
                         'recipients as failed_count'    => fn($q) => $q->where('delivery_status','failed')])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => collect($messages->items())->map(fn($m) => [
                'id'              => $m->id,
                'subject'         => $m->subject,
                'body_preview'    => mb_substr($m->body, 0, 140) . (mb_strlen($m->body) > 140 ? '…' : ''),
                'channel'         => $m->channel,
                'status'          => $m->status,
                'recipient_group' => $m->recipient_group,
                'sender'          => $m->sender?->name,
                'total_recipients'=> $m->recipients_count,
                'delivered_count' => $m->delivered_count,
                'failed_count'    => $m->failed_count,
                'sent_at'         => $m->sent_at?->diffForHumans(),
                'created_at'      => $m->created_at->format('Y-m-d H:i'),
            ]),
            'meta' => [
                'total'        => $messages->total(),
                'per_page'     => $messages->perPage(),
                'current_page' => $messages->currentPage(),
                'last_page'    => $messages->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $message = Message::where('branch_id', $request->user()->branch_id)
            ->with(['sender', 'recipients.member'])
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id'              => $message->id,
                'subject'         => $message->subject,
                'body'            => $message->body,
                'channel'         => $message->channel,
                'status'          => $message->status,
                'recipient_group' => $message->recipient_group,
                'sender'          => $message->sender?->name,
                'sent_at'         => $message->sent_at?->format('Y-m-d H:i'),
                'created_at'      => $message->created_at->format('Y-m-d H:i'),
                'total_recipients'=> $message->total_recipients,
                'delivered_count' => $message->delivered_count,
                'failed_count'    => $message->failed_count,
                'recipients'      => $message->recipients->map(fn($r) => [
                    'id'              => $r->id,
                    'name'            => $r->member?->full_name ?? '—',
                    'email'           => $r->email,
                    'phone'           => $r->phone,
                    'delivery_status' => $r->delivery_status,
                    'delivered_at'    => $r->delivered_at?->diffForHumans(),
                    'failure_reason'  => $r->failure_reason,
                ]),
            ],
        ]);
    }

    public function recipientCount(Request $request): JsonResponse
    {
        $request->validate([
            'recipient_group' => ['required', 'in:all,department,gender,status,individual'],
            'department_id'   => ['nullable', 'uuid'],
            'gender'          => ['nullable', 'in:male,female'],
            'status'          => ['nullable', 'in:active,inactive,transferred,deceased'],
            'member_ids'      => ['nullable', 'array'],
            'channel'         => ['required', 'in:sms,email,both'],
        ]);

        $count = $this->resolveRecipients($request)->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'subject'         => ['nullable', 'string', 'max:200'],
            'body'            => ['required', 'string'],
            'channel'         => ['required', 'in:sms,email,both'],
            'recipient_group' => ['required', 'in:all,department,gender,status,individual'],
            'department_id'   => ['nullable', 'uuid'],
            'gender'          => ['nullable', 'in:male,female'],
            'status'          => ['nullable', 'in:active,inactive,transferred,deceased'],
            'member_ids'      => ['nullable', 'array'],
        ]);

        $recipients = $this->resolveRecipients($request)->get();

        if ($recipients->isEmpty()) {
            return response()->json([
                'message' => 'No recipients match the selected criteria.',
            ], 422);
        }

        $message = DB::transaction(function () use ($request, $recipients) {
            $msg = Message::create([
                'branch_id'       => $request->user()->branch_id,
                'sender_id'       => $request->user()->id,
                'subject'         => $request->subject,
                'body'            => $request->body,
                'channel'         => $request->channel,
                'status'          => 'sending',
                'recipient_group' => $request->recipient_group,
                'department_id'   => $request->department_id,
                'sent_at'         => now(),
            ]);

            foreach ($recipients as $member) {
                $recipient = MessageRecipient::create([
                    'message_id' => $msg->id,
                    'member_id'  => $member->id,
                    'phone'      => $member->phone,
                    'email'      => $member->email,
                    'delivery_status' => 'pending',
                ]);

                // Dispatch via sync driver for now — wire queue in production
                SendBroadcastMessageJob::dispatchSync($recipient->id);
            }

            $msg->update(['status' => 'sent']);
            return $msg;
        });

        activity()->causedBy($request->user())
                  ->performedOn($message)
                  ->log("Sent {$request->channel} message to {$recipients->count()} recipients");

        return response()->json([
            'message' => "Message sent to {$recipients->count()} recipients.",
            'data'    => ['id' => $message->id],
        ], 201);
    }

    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        $now      = now();

        return response()->json([
            'data' => [
                'total_sent'  => Message::where('branch_id', $branchId)->where('status','sent')->count(),
                'this_month'  => Message::where('branch_id', $branchId)
                                        ->whereMonth('created_at', $now->month)
                                        ->whereYear('created_at', $now->year)
                                        ->count(),
                'total_recipients' => MessageRecipient::whereHas('message',
                                          fn($q) => $q->where('branch_id', $branchId))->count(),
            ],
        ]);
    }

    /**
     * Resolve a Member query based on recipient_group choice.
     */
    protected function resolveRecipients(Request $request)
    {
        $branchId = $request->user()->branch_id;
        $query    = Member::where('branch_id', $branchId);

        // Filter to recipients with valid contact info
        if ($request->channel === 'email') {
            $query->whereNotNull('email')->where('email', '!=', '');
        } elseif ($request->channel === 'sms') {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        } else {
            $query->where(function ($q) {
                $q->whereNotNull('email')->where('email', '!=', '')
                  ->orWhereNotNull('phone')->where('phone', '!=', '');
            });
        }

        switch ($request->recipient_group) {
            case 'all':
                $query->where('status', 'active');
                break;
            case 'department':
                if ($request->department_id) {
                    $query->whereHas('departments', fn($q) =>
                        $q->where('departments.id', $request->department_id));
                }
                break;
            case 'gender':
                $query->where('status', 'active');
                if ($request->gender) {
                    $query->where('gender', $request->gender);
                }
                break;
            case 'status':
                if ($request->status) {
                    $query->where('status', $request->status);
                }
                break;
            case 'individual':
                $query->whereIn('id', $request->member_ids ?? []);
                break;
        }

        return $query;
    }
}
