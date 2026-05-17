<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMemberRequest;
use App\Http\Requests\Member\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    // GET /api/members
    public function index(Request $request): JsonResponse
    {
        $query = Member::query()
            ->where('branch_id', $request->user()->branch_id);

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name',    'ilike', "%{$search}%")
                  ->orWhere('last_name',   'ilike', "%{$search}%")
                  ->orWhere('other_names', 'ilike', "%{$search}%")
                  ->orWhere('phone',       'ilike', "%{$search}%")
                  ->orWhere('member_number', 'ilike', "%{$search}%");
            });
        }

        // Filters
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($gender = $request->get('gender')) {
            $query->where('gender', $gender);
        }

        $members = $query
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => MemberResource::collection($members->items()),
            'meta' => [
                'total'        => $members->total(),
                'per_page'     => $members->perPage(),
                'current_page' => $members->currentPage(),
                'last_page'    => $members->lastPage(),
            ],
        ]);
    }

    // POST /api/members
    public function store(StoreMemberRequest $request): JsonResponse
    {
        $member = Member::create([
            ...$request->validated(),
            'branch_id' => $request->user()->branch_id,
            'status'    => $request->get('status', 'active'),
            'is_baptised' => $request->boolean('is_baptised'),
        ]);

        activity()->causedBy($request->user())
                  ->performedOn($member)
                  ->log("Registered new member: {$member->full_name}");

        return response()->json([
            'message' => 'Member registered successfully.',
            'data'    => new MemberResource($member),
        ], 201);
    }

    // GET /api/members/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $member = Member::where('branch_id', $request->user()->branch_id)
                        ->findOrFail($id);

        return response()->json(['data' => new MemberResource($member)]);
    }

    // PUT /api/members/{id}
    public function update(UpdateMemberRequest $request, string $id): JsonResponse
    {
        $member = Member::where('branch_id', $request->user()->branch_id)
                        ->findOrFail($id);

        $member->update($request->validated());

        activity()->causedBy($request->user())
                  ->performedOn($member)
                  ->log("Updated member: {$member->full_name}");

        return response()->json([
            'message' => 'Member updated successfully.',
            'data'    => new MemberResource($member),
        ]);
    }

    // DELETE /api/members/{id}
    public function destroy(Request $request, string $id): JsonResponse
    {
        $member = Member::where('branch_id', $request->user()->branch_id)
                        ->findOrFail($id);

        $name = $member->full_name;
        $member->delete();

        activity()->causedBy($request->user())
                  ->log("Deleted member: {$name}");

        return response()->json(['message' => 'Member deleted successfully.']);
    }

    // GET /api/members/stats
    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        return response()->json([
            'data' => [
                'total'       => Member::where('branch_id', $branchId)->count(),
                'active'      => Member::where('branch_id', $branchId)->where('status', 'active')->count(),
                'inactive'    => Member::where('branch_id', $branchId)->where('status', 'inactive')->count(),
                'transferred' => Member::where('branch_id', $branchId)->where('status', 'transferred')->count(),
                'male'        => Member::where('branch_id', $branchId)->where('gender', 'male')->count(),
                'female'      => Member::where('branch_id', $branchId)->where('gender', 'female')->count(),
                'new_this_month' => Member::where('branch_id', $branchId)
                                          ->whereMonth('created_at', now()->month)
                                          ->whereYear('created_at', now()->year)
                                          ->count(),
            ],
        ]);
    }
}
