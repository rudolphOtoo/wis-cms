<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\Department;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepartmentController extends Controller
{
    /**
     * A department query scoped to what the user may access.
     * Admin-type roles see all branch departments; a department_leader
     * sees only the department(s) they lead.
     */
    protected function scopedQuery(Request $request)
    {
        $user = $request->user();

        $query = Department::query()->where('branch_id', $user->branch_id);

        $seesAll = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);

        if (! $seesAll && $user->hasRole('department_leader')) {
            $query->where('leader_user_id', $user->id);
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $departments = $this->scopedQuery($request)
            ->with('leader')
            ->withCount('members')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => DepartmentResource::collection($departments),
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create([
            ...$request->validated(),
            'branch_id' => $request->user()->branch_id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        activity()->causedBy($request->user())
            ->performedOn($department)
            ->log("Created department: {$department->name}");

        return response()->json([
            'message' => 'Department created successfully.',
            'data' => new DepartmentResource($department->load('leader')),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $department = $this->scopedQuery($request)
            ->with(['leader', 'members'])
            ->withCount('members')
            ->findOrFail($id);

        return response()->json(['data' => new DepartmentResource($department)]);
    }

    public function update(UpdateDepartmentRequest $request, string $id): JsonResponse
    {
        $department = $this->scopedQuery($request)
            ->findOrFail($id);

        $department->update($request->validated());

        activity()->causedBy($request->user())
            ->performedOn($department)
            ->log("Updated department: {$department->name}");

        return response()->json([
            'message' => 'Department updated successfully.',
            'data' => new DepartmentResource($department->load('leader')),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $department = $this->scopedQuery($request)
            ->findOrFail($id);

        $name = $department->name;
        $department->delete();

        activity()->causedBy($request->user())
            ->log("Deleted department: {$name}");

        return response()->json(['message' => 'Department deleted successfully.']);
    }

    // GET /api/departments/{id}/members
    public function departmentMembers(Request $request, string $id): JsonResponse
    {
        $department = $this->scopedQuery($request)
            ->findOrFail($id);

        $members = $department->members()
            ->orderBy('first_name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'member_number' => $m->member_number,
                'phone' => $m->phone,
                'role' => $m->pivot->role,
                'joined_at' => $m->pivot->joined_at,
            ]);

        return response()->json(['data' => $members]);
    }

    // POST /api/departments/{id}/members
    public function addMember(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'member_id' => ['required', 'uuid', 'exists:members,id'],
            'role' => ['nullable', 'string', 'max:50'],
        ]);

        $department = $this->scopedQuery($request)
            ->findOrFail($id);

        // Prevent duplicate
        if ($department->members()->where('member_id', $request->member_id)->exists()) {
            return response()->json(['message' => 'Member is already in this department.'], 422);
        }

        $department->members()->attach($request->member_id, [
            'role' => $request->get('role', 'member'),
            'joined_at' => now()->toDateString(),
        ]);

        $member = Member::find($request->member_id);

        activity()->causedBy($request->user())
            ->log("Added {$member->full_name} to {$department->name}");

        return response()->json(['message' => 'Member added to department successfully.']);
    }

    // DELETE /api/departments/{id}/members/{memberId}
    public function removeMember(Request $request, string $id, string $memberId): JsonResponse
    {
        $department = $this->scopedQuery($request)
            ->findOrFail($id);

        $department->members()->detach($memberId);

        activity()->causedBy($request->user())
            ->log("Removed member from {$department->name}");

        return response()->json(['message' => 'Member removed from department.']);
    }

    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        $departments = Department::where('branch_id', $branchId)
            ->withCount('members')
            ->get();

        return response()->json([
            'data' => [
                'total' => $departments->count(),
                'active' => $departments->where('is_active', true)->count(),
                'total_members_assigned' => $departments->sum('members_count'),
            ],
        ]);
    }

    /**
     * A department leader sends a message to their own department's members.
     * Scoped: the user must lead the department (scopedQuery enforces this).
     * Reuses the broadcast pipeline (SendBroadcastMessageJob).
     */
    public function message(Request $request, string $id): JsonResponse
    {
        $department = $this->scopedQuery($request)->findOrFail($id);

        $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'channel' => ['required', 'in:sms,email,both'],
        ]);

        // Recipients = this department's members with valid contact info
        // for the chosen channel.
        $recipients = $department->members()
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
                'message' => 'No members in this department have contact details for the selected channel.',
            ], 422);
        }

        $message = DB::transaction(function () use ($request, $department, $recipients) {
            $msg = Message::create([
                'branch_id' => $request->user()->branch_id,
                'sender_id' => $request->user()->id,
                'subject' => $request->subject,
                'body' => $request->body,
                'channel' => $request->channel,
                'status' => 'sending',
                'recipient_group' => 'department',
                'department_id' => $department->id,
                'sent_at' => now(),
            ]);

            foreach ($recipients as $member) {
                $r = MessageRecipient::create([
                    'message_id' => $msg->id,
                    'member_id' => $member->id,
                    'phone' => $member->phone,
                    'email' => $member->email,
                    'delivery_status' => 'pending',
                ]);
                SendBroadcastMessageJob::dispatch($r->id);
            }

            $msg->update(['status' => 'sent']);

            return $msg;
        });

        activity()->causedBy($request->user())
            ->performedOn($message)
            ->log("Department leader messaged {$recipients->count()} members of {$department->name}");

        return response()->json([
            'message' => "Message sent to {$recipients->count()} members of {$department->name}.",
            'data' => ['id' => $message->id],
        ], 201);
    }
}
