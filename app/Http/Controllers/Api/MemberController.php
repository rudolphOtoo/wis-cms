<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMemberRequest;
use App\Http\Requests\Member\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Cell;
use App\Models\Department;
use App\Models\Member;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('other_names', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
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
                'total' => $members->total(),
                'per_page' => $members->perPage(),
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
            ],
        ]);
    }

    // POST /api/members
    public function store(StoreMemberRequest $request): JsonResponse
    {
        $member = Member::create([
            ...$request->validated(),
            'branch_id' => $request->user()->branch_id,
            'status' => $request->get('status', 'active'),
            'is_baptised' => $request->boolean('is_baptised'),
        ]);

        activity()->causedBy($request->user())
            ->performedOn($member)
            ->log("Registered new member: {$member->full_name}");

        return response()->json([
            'message' => 'Member registered successfully.',
            'data' => new MemberResource($member),
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
            'data' => new MemberResource($member),
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
    // GET /api/members/export  — streams a CSV of the (optionally filtered) member list
    public function export(Request $request): StreamedResponse
    {
        $query = Member::query()
            ->where('branch_id', $request->user()->branch_id);

        // Mirror the same filters as index() so the export matches what
        // the user is looking at.
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('other_names', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('member_number', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($gender = $request->get('gender')) {
            $query->where('gender', $gender);
        }

        $query->orderBy('first_name')->orderBy('last_name');

        $filename = 'members-'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($query) {
            $writer = new Writer;
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues([
                'Member Number', 'First Name', 'Last Name', 'Other Names',
                'Phone', 'Email', 'Gender', 'Status', 'Join Date',
            ]));

            // lazy() streams rows in chunks so a large membership doesn't
            // exhaust memory.
            $query->lazy()->each(function ($m) use ($writer) {
                $writer->addRow(Row::fromValues([
                    $m->member_number,
                    $m->first_name,
                    $m->last_name,
                    $m->other_names,
                    $m->phone,
                    $m->email,
                    $m->gender,
                    $m->status,
                    optional($m->join_date)->toDateString(),
                ]));
            });

            $writer->close();
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        return response()->json([
            'data' => [
                'total' => Member::where('branch_id', $branchId)->count(),
                'active' => Member::where('branch_id', $branchId)->where('status', 'active')->count(),
                'inactive' => Member::where('branch_id', $branchId)->where('status', 'inactive')->count(),
                'transferred' => Member::where('branch_id', $branchId)->where('status', 'transferred')->count(),
                'male' => Member::where('branch_id', $branchId)->where('gender', 'male')->count(),
                'female' => Member::where('branch_id', $branchId)->where('gender', 'female')->count(),
                'new_this_month' => Member::where('branch_id', $branchId)
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
            ],
        ]);
    }

    public function giving(Request $request, string $id): JsonResponse
    {
        $member = Member::where('branch_id', $request->user()->branch_id)->findOrFail($id);
        $year = $request->get('year', now()->format('Y'));

        $transactions = $member->transactions()
            ->where('type', 'income')
            ->whereYear('transaction_date', $year)
            ->with('category')
            ->orderByDesc('transaction_date')
            ->get();

        $byCategory = $transactions
            ->groupBy(fn ($t) => $t->category?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'category' => $group->first()->category?->name ?? 'Uncategorised',
                'count' => $group->count(),
                'total' => round($group->sum('amount'), 2),
            ])->values();

        $availableYears = $member->transactions()
            ->where('type', 'income')
            ->selectRaw('EXTRACT(YEAR FROM transaction_date) as yr')
            ->distinct()->orderByDesc('yr')
            ->pluck('yr')->map(fn ($y) => (int) $y);

        return response()->json([
            'data' => [
                'member' => [
                    'id' => $member->id,
                    'full_name' => $member->full_name,
                    'member_number' => $member->member_number,
                ],
                'year' => (int) $year,
                'available_years' => $availableYears,
                'total' => round($transactions->sum('amount'), 2),
                'by_category' => $byCategory,
                'transactions' => $transactions->map(fn ($t) => [
                    'id' => $t->id,
                    'date' => $t->transaction_date->format('Y-m-d'),
                    'category' => $t->category?->name ?? 'Uncategorised',
                    'amount' => round($t->amount, 2),
                    'reference' => $t->reference,
                ]),
            ],
        ]);
    }

    public function givingStatement(Request $request, string $id)
    {
        $member = Member::where('branch_id', $request->user()->branch_id)
            ->with('branch')->findOrFail($id);
        $year = $request->get('year', now()->format('Y'));

        $transactions = $member->transactions()
            ->where('type', 'income')
            ->whereYear('transaction_date', $year)
            ->with('category')
            ->orderBy('transaction_date')
            ->get();

        $byCategory = $transactions
            ->groupBy(fn ($t) => $t->category?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'category' => $group->first()->category?->name ?? 'Uncategorised',
                'total' => round($group->sum('amount'), 2),
            ])->values();

        $pdf = Pdf::loadView('pdf.giving-statement', [
            'member' => $member,
            'year' => $year,
            'transactions' => $transactions,
            'byCategory' => $byCategory,
            'total' => round($transactions->sum('amount'), 2),
            'branchName' => $member->branch?->name ?? 'Wesleyan International Society',
            'generatedAt' => now()->format('F j, Y'),
        ]);

        return $pdf->download("giving-statement-{$member->member_number}-{$year}.pdf");
    }

    /**
     * Promote a Member to leadership of a specific cell or department.
     *
     * This is the atomic, church-native promotion workflow: a Member
     * (already in the congregation) is appointed to lead a specific
     * unit, in one transaction creating their User account, assigning
     * their role, linking the User<->Member relationship, and setting
     * them as the unit's leader. Returns a temp password (shown once).
     *
     * Architecture: matches the "membership-first leadership" model
     * from the design review. Cannot promote a Member who already has
     * a User account, or appoint to a unit that already has a leader
     * (must explicitly demote first).
     */
    public function promoteToLeader(Request $request, string $id): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        $member = Member::where('branch_id', $branchId)->findOrFail($id);

        $data = $request->validate([
            'leadership_type' => ['required', 'in:cell,department'],
            'target_id' => ['required', 'uuid'],
            'email' => ['required', 'email', 'unique:users,email', 'max:150'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        if (User::where('member_id', $member->id)->exists()) {
            return response()->json([
                'message' => 'This member already has a user account. Edit their roles directly.',
            ], 422);
        }

        $unitClass = $data['leadership_type'] === 'cell' ? Cell::class : Department::class;
        $unit = $unitClass::where('branch_id', $branchId)->findOrFail($data['target_id']);

        if ($unit->leader_user_id) {
            return response()->json([
                'message' => "This {$data['leadership_type']} already has a leader. Demote them first.",
            ], 422);
        }

        $tempPassword = Str::password(12);
        $roleName = $data['leadership_type'] === 'cell' ? 'cell_leader' : 'department_leader';

        $user = DB::transaction(function () use ($member, $unit, $data, $tempPassword, $roleName, $branchId) {
            $newUser = User::create([
                'branch_id' => $branchId,
                'name' => $data['name'] ?? trim("{$member->first_name} {$member->last_name}"),
                'email' => $data['email'],
                'password' => Hash::make($tempPassword),
                'is_active' => true,
                'must_change_password' => true,
                'member_id' => $member->id,
            ]);
            $newUser->assignRole($roleName);
            $unit->update(['leader_user_id' => $newUser->id]);

            return $newUser;
        });

        activity()->causedBy($request->user())
            ->performedOn($user)
            ->log("Promoted member {$member->first_name} {$member->last_name} to {$roleName} of {$unit->name}");

        return response()->json([
            'message' => "{$member->first_name} promoted to {$roleName} of {$unit->name}.",
            'data' => [
                'user_id' => $user->id,
                'role' => $roleName,
                'unit' => ['id' => $unit->id, 'name' => $unit->name],
            ],
            'temp_password' => $tempPassword,
        ], 201);
    }

    /**
     * Create a User account for a Member (member-portal login).
     *
     * Parallel to promoteToLeader but for the simpler case: a Member
     * gets a login WITHOUT being appointed to lead anything. They
     * get the 'member' role and can sign in to /portal to view their
     * giving, attendance, and profile.
     *
     * Architecture: same atomic-transaction shape as promoteToLeader.
     * The User is linked to the Member via member_id from the start;
     * the UNIQUE constraint ensures one-User-per-Member at the DB level.
     */
    public function createLogin(Request $request, string $id): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        $member = Member::where('branch_id', $branchId)->findOrFail($id);

        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users,email', 'max:150'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        if (User::where('member_id', $member->id)->exists()) {
            return response()->json([
                'message' => 'This member already has a user account.',
            ], 422);
        }

        $tempPassword = Str::password(12);

        $user = DB::transaction(function () use ($member, $data, $tempPassword, $branchId) {
            $newUser = User::create([
                'branch_id' => $branchId,
                'name' => $data['name'] ?? trim("{$member->first_name} {$member->last_name}"),
                'email' => $data['email'],
                'password' => Hash::make($tempPassword),
                'is_active' => true,
                'must_change_password' => true,
                'member_id' => $member->id,
            ]);
            $newUser->assignRole('member');

            return $newUser;
        });

        activity()->causedBy($request->user())
            ->performedOn($user)
            ->log("Created member-portal login for {$member->first_name} {$member->last_name}");

        return response()->json([
            'message' => "Login created for {$member->first_name}.",
            'data' => ['user_id' => $user->id, 'role' => 'member'],
            'temp_password' => $tempPassword,
        ], 201);
    }
}
