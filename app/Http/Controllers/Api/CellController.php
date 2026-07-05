<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cell\AssignCellMemberRequest;
use App\Http\Requests\Cell\CellMessageRequest;
use App\Http\Requests\Cell\StoreCellRequest;
use App\Http\Requests\Cell\UpdateCellRequest;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\Cell;
use App\Models\Children;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Database\Eloquent\Builder;
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
    protected function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = Cell::query();

        $seesAll = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);

        if (! $seesAll && $user->hasRole('cell_leader')) {
            $query->where('leader_user_id', $user->id);
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 50);
        $cells = $this->scopedQuery($request)
            ->with('leader')
            ->withCount(['members', 'children'])
            ->orderBy('name')
            ->paginate($perPage)
            ->through(fn ($c) => $this->shape($c));

        return response()->json($cells);
    }

    public function store(StoreCellRequest $request): JsonResponse
    {
        $data = $request->validated();

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
            'data' => $this->shape($cell->load('leader')->loadCount(['members', 'children'])),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $cell = $this->scopedQuery($request)
            ->with([
                'leader',
                'members' => fn ($q) => $q->orderBy('first_name'),
                'children' => fn ($q) => $q->with('guardian')->orderBy('first_name'),
            ])
            ->withCount(['members', 'children'])
            ->findOrFail($id);

        return response()->json(['data' => $this->shape($cell, withMembers: true)]);
    }

    public function update(UpdateCellRequest $request, string $id): JsonResponse
    {
        $cell = $this->scopedQuery($request)->findOrFail($id);
        $data = $request->validated();

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
    public function assignMember(AssignCellMemberRequest $request, string $id, string $memberId): JsonResponse
    {
        $cell = Cell::findOrFail($id);
        $this->authorize('addMember', $cell);

        abort_if($cell->name === 'Children Ministry', 422,
            'The Children Ministry cell is for children only. Adult members cannot be assigned here.');

        $member = Member::findOrFail($memberId);
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
        $cell = Cell::findOrFail($id);
        $this->authorize('removeMember', $cell);

        $member = Member::where('cell_id', $cell->id)->findOrFail($memberId);
        $member->update(['cell_id' => null]);

        activity()->causedBy($request->user())
            ->performedOn($cell)
            ->log("Removed {$member->first_name} {$member->last_name} from cell {$cell->name}");

        return response()->json([
            'message' => "{$member->first_name} removed from {$cell->name}.",
        ]);
    }

    public function assignChild(Request $request, string $id, string $childId): JsonResponse
    {
        $cell = Cell::findOrFail($id);
        $this->authorize('assignChild', $cell);

        abort_if($cell->name !== 'Children Ministry', 422,
            'Only the Children Ministry cell can have children assigned.');

        $child = Children::findOrFail($childId);

        abort_if($child->cell_id === $cell->id, 422,
            "{$child->full_name} is already assigned to {$cell->name}.");

        $child->update(['cell_id' => $cell->id]);

        activity()->causedBy($request->user())
            ->performedOn($cell)
            ->log("Assigned child {$child->full_name} to {$cell->name}");

        return response()->json([
            'message' => "{$child->full_name} assigned to {$cell->name}.",
            'data' => $this->shape($cell->loadCount('members')),
        ]);
    }

    public function unassignChild(Request $request, string $id, string $childId): JsonResponse
    {
        $cell = Cell::findOrFail($id);
        $this->authorize('removeChild', $cell);

        $child = Children::where('cell_id', $cell->id)->findOrFail($childId);
        $child->update(['cell_id' => null]);

        activity()->causedBy($request->user())
            ->performedOn($cell)
            ->log("Removed child {$child->full_name} from cell {$cell->name}");

        return response()->json([
            'message' => "{$child->full_name} removed from {$cell->name}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
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
            'members_count' => $cell->members_count
                ?? ($cell->relationLoaded('members') ? $cell->members->count() : 0),
            'children_count' => $cell->children_count
                ?? ($cell->relationLoaded('children') ? $cell->children->count() : 0),
        ];

        if ($withMembers) {
            $out['members'] = $cell->members->map(fn ($m) => [
                'id' => $m->id,
                'first_name' => $m->first_name,
                'last_name' => $m->last_name,
                'phone' => $m->phone,
                'status' => $m->status,
            ])->all();

            $out['children'] = $cell->children->map(fn ($c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'full_name' => $c->full_name,
                'class_group' => $c->class_group,
                'is_active' => $c->is_active,
                'guardian' => $c->guardian ? [
                    'id' => $c->guardian->id,
                    'name' => $c->guardian->full_name,
                ] : null,
            ])->all();
        }

        return $out;
    }

    /**
     * Send a broadcast message to all members of this cell.
     *
     * Permission: cell_leader gets 'message own cell'; admins bypass via
     * the route middleware. scopedQuery ensures a cell_leader can only
     * target cells they actually lead — findOrFail returns 404 otherwise.
     *
     * Mirrors DepartmentController::message; only differences are
     * 'cell_id' vs 'department_id' and recipient_group = 'cell'.
     */
    public function message(CellMessageRequest $request, string $id): JsonResponse
    {
        $cell = $this->scopedQuery($request)->findOrFail($id);
        $validated = $request->validated();
        $channel = $validated['channel'];

        $recipients = $cell->members()
            ->where(function ($q) use ($channel): void {
                if ($channel === 'email') {
                    $q->whereNotNull('email')->where('email', '!=', '');
                } elseif ($channel === 'sms') {
                    $q->whereNotNull('phone')->where('phone', '!=', '');
                } else {
                    $q->where(function ($inner): void {
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

        $recipientIds = [];
        $msg = DB::transaction(function () use ($request, $validated, $cell, $recipients, &$recipientIds) {
            $message = Message::create([
                'branch_id' => $request->user()->branch_id,
                'sender_id' => $request->user()->id,
                'subject' => $validated['subject'] ?? null,
                'body' => $validated['body'],
                'channel' => $validated['channel'],
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
                $recipientIds[] = $r->id;
            }

            $message->update(['status' => 'sent']);

            return $message;
        });

        foreach ($recipientIds as $rid) {
            SendBroadcastMessageJob::dispatch($rid);
        }

        activity()->causedBy($request->user())
            ->performedOn($msg)
            ->log("Sent message to {$cell->name} cell ({$recipients->count()} recipients)");

        return response()->json([
            'message' => "Message queued for {$recipients->count()} member(s) of {$cell->name}.",
            'data' => ['message_id' => $msg->id, 'recipient_count' => $recipients->count()],
        ], 201);
    }
}
