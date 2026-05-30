<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cell;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
