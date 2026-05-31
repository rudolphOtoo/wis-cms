<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\Cell;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CellController extends Controller
{
    /**
     * Cells scoped to the user's branch. Admin-type roles manage cells;
     * cell leadership is a pointer (leader_user_id) for now, not a
     * scoped login role — so no per-leader filtering here yet.
     */
    protected function scopedQuery(Request $request)
    {
        $user = $request->user();
        $query = Cell::query()->where('branch_id', $user->branch_id);
        $seesAll = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);
        if (! $seesAll && $user->hasRole('cell_leader')) {
            $query->where('leader_user_id', $user->id);
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $cells = $this->scopedQuery($request)
            ->with('leader')
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => $this->shape($c));

        return response()->json(['data' => $cells]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'leader_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'is_active' => ['boolean'],
        ]);

        $cell = Cell::create([
            ...$data,
            'branch_id' => $request->user()->branch_id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        activity()->causedBy($request->user())
            ->performedOn($cell)
            ->log("Created cell: {$cell->name}");

        return response()->json([
            'message' => 'Cell created successfully.',
            'data' => $this->shape($cell->load('leader')->loadCount('members')),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $cell = $this->scopedQuery($request)
            ->with(['leader', 'members' => fn ($q) => $q->orderBy('first_name')])
            ->withCount('members')
            ->findOrFail($id);

        return response()->json(['data' => $this->shape($cell, withMembers: true)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $cell = $this->scopedQuery($request)->findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'leader_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'is_active' => ['boolean'],
        ]);

        $cell->update($data);

        activity()->causedBy($request->user())
            ->performedOn($cell)
            ->log("Updated cell: {$cell->name}");

        return response()->json([
            'message' => 'Cell updated successfully.',
            'data' => $this->shape($cell->load('leader')->loadCount('members')),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $cell = $this->scopedQuery($request)->findOrFail($id);
        $name = $cell->name;
        // Members' cell_id auto-nulls via the nullOnDelete FK.
        $cell->delete();

        activity()->causedBy($request->user())->log("Deleted cell: {$name}");

        return response()->json(['message' => 'Cell deleted successfully.']);
    }

    /**
     * Assign a member to this cell. Because a member has exactly one
     * cell_id, this automatically REPLACES any previous cell — no
     * pivot cleanup needed.
     */
    public function assignMember(Request $request, string $id, string $memberId): JsonResponse
    {
        $cell = $this->scopedQuery($request)->findOrFail($id);

        $member = Member::where('branch_id', $request->user()->branch_id)
            ->findOrFail($memberId);

        $member->update(['cell_id' => $cell->id]);

        activity()->causedBy($request->user())
            ->performedOn($cell)
            ->log("Assigned {$member->first_name} {$member->last_name} to cell {$cell->name}");

        return response()->json([
            'message' => "{$member->first_name} assigned to {$cell->name}.",
            'data' => $this->shape($cell->loadCount('members')),
        ]);
    }

    public function unassignMember(Request $request, string $id, string $memberId): JsonResponse
    {
        $cell = $this->scopedQuery($request)->findOrFail($id);

        $member = Member::where('branch_id', $request->user()->branch_id)
            ->where('cell_id', $cell->id)
            ->findOrFail($memberId);

        $member->update(['cell_id' => null]);

        activity()->causedBy($request->user())
            ->performedOn($cell)
            ->log("Removed {$member->first_name} {$member->last_name} from cell {$cell->name}");

        return response()->json([
            'message' => "{$member->first_name} removed from {$cell->name}.",
        ]);
    }

    protected function shape(Cell $cell, bool $withMembers = false): array
    {
        $out = [
            'id' => $cell->id,
            'name' => $cell->name,
            'description' => $cell->description,
            'is_active' => $cell->is_active,
            'leader_user_id' => $cell->leader_user_id,
            'leader' => $cell->leader ? [
                'id' => $cell->leader->id,
                'name' => $cell->leader->name,
            ] : null,
            'members_count' => $cell->members_count ?? $cell->members()->count(),
        ];

        if ($withMembers) {
            $out['members'] = $cell->members->map(fn ($m) => [
                'id' => $m->id,
                'first_name' => $m->first_name,
                'last_name' => $m->last_name,
                'phone' => $m->phone,
                'status' => $m->status,
            ])->all();
        }

        return $out;
    }

    /**
     * Send a broadcast message to all members of this cell.
     *
     * Permission: cell_leader gets 'message own cell'; admins
     * bypass via the route middleware. scopedQuery (which is
     * ownership-aware after the Phase 2 fix) ensures a cell_leader
     * can only target cells they actually lead - findOrFail returns
     * 404 if they pass someone else's id.
     *
     * Mirrors DepartmentController::message; the only differences
     * are 'cell_id' instead of 'department_id' on the message row
     * and 'recipient_group' = 'cell'.
     */
    public function message(Request $request, string $id): JsonResponse
    {
        $cell = $this->scopedQuery($request)->findOrFail($id);

        $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'channel' => ['required', 'in:sms,email,both'],
        ]);

        $recipients = $cell->members()
            ->where(function ($q) use ($request) {
                if ($request->channel === 'email') {
                    $q->whereNotNull('email')->where('email', '!=', '');
                } elseif ($request->channel === 'sms') {
                    $q->whereNotNull('phone')->where('phone', '!=', '');
                } else {
                    $q->where(function ($inner) {
                        $inner->whereNotNull('email')->where('email', '!=', '')
                            ->orWhereNotNull('phone')->where('phone', '!=', '');
                    });
                }
            })
            ->get();

        if ($recipients->isEmpty()) {
            return response()->json([
                'message' => 'No members in this cell have contact details for the selected channel.',
            ], 422);
        }

        $msg = DB::transaction(function () use ($request, $cell, $recipients) {
            $message = Message::create([
                'branch_id' => $request->user()->branch_id,
                'sender_id' => $request->user()->id,
                'subject' => $request->subject,
                'body' => $request->body,
                'channel' => $request->channel,
                'status' => 'sending',
                'recipient_group' => 'cell',
                'cell_id' => $cell->id,
                'sent_at' => now(),
            ]);

            foreach ($recipients as $member) {
                $r = MessageRecipient::create([
                    'message_id' => $message->id,
                    'member_id' => $member->id,
                    'phone' => $member->phone,
                    'email' => $member->email,
                    'delivery_status' => 'pending',
                ]);
                SendBroadcastMessageJob::dispatch($r->id);
            }

            $message->update(['status' => 'sent']);

            return $message;
        });

        activity()->causedBy($request->user())
            ->performedOn($msg)
            ->log("Sent message to {$cell->name} cell ({$recipients->count()} recipients)");

        return response()->json([
            'message' => "Message queued for {$recipients->count()} member(s) of {$cell->name}.",
            'data' => ['message_id' => $msg->id, 'recipient_count' => $recipients->count()],
        ], 201);
    }
}
