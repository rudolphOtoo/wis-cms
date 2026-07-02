<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMemberRequest;
use App\Http\Requests\Member\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Http\Resources\TransactionResource;
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
        $query = Member::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('other_names', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('member_number', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($gender = $request->input('gender')) {
            $query->where('gender', $gender);
        }

        $user = $request->user();
        if (! $request->input('unscoped') && $user->hasRole('cell_leader')) {
            $cellIds = Cell::where('leader_user_id', $user->id)->pluck('id');
            $query->whereIn('cell_id', $cellIds);
        } elseif ($cellId = $request->input('cell_id')) {
            $query->where('cell_id', $cellId);
        }

        $members = $query
            // PERF FIX: withExists() adds a single correlated sub-select for
            // the entire page, injecting `user_exists` as a boolean column.
            // MemberResource reads $this->user_exists, eliminating the N+1
            // $this->user()->exists() call that previously fired once per row.
            ->withExists('user')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate($request->integer('per_page', 20));

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
            'status' => $request->input('status', 'active'),
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
    public function show(string $id): JsonResponse
    {
        $member = Member::query()->withExists('user')->findOrFail($id);

        return response()->json(['data' => new MemberResource($member)]);
    }

    // PUT /api/members/{id}
    public function update(UpdateMemberRequest $request, string $id): JsonResponse
    {
        $member = Member::query()->findOrFail($id);
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
        $member = Member::query()->findOrFail($id);
        $name = $member->full_name;
        $member->delete();

        activity()->causedBy($request->user())
            ->log("Deleted member: {$name}");

        return response()->json(['message' => 'Member deleted successfully.']);
    }

    // GET /api/members/export
    public function export(Request $request): StreamedResponse
    {
        $query = Member::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('other_names', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('member_number', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($gender = $request->input('gender')) {
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

    // GET /api/members/stats
    public function stats(Request $request): JsonResponse
    {
        $query = Member::query();

        $user = $request->user();
        if ($user->hasRole('cell_leader')) {
            $cellIds = Cell::where('leader_user_id', $user->id)->pluck('id');
            $query->whereIn('cell_id', $cellIds);
        }

        $stats = $query->selectRaw("
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'active') AS active,
            COUNT(*) FILTER (WHERE status = 'inactive') AS inactive,
            COUNT(*) FILTER (WHERE status = 'transferred') AS transferred,
            COUNT(*) FILTER (WHERE gender = 'male') AS male,
            COUNT(*) FILTER (WHERE gender = 'female') AS female,
            COUNT(*) FILTER (
                WHERE EXTRACT(MONTH FROM created_at) = ?
                  AND EXTRACT(YEAR FROM created_at) = ?
            ) AS new_this_month
        ", [now()->month, now()->year])->first();

        return response()->json(['data' => $stats]);
    }

    /**
     * GET /api/members/{id}/giving
     *
     * HIGH-02 FIX: The `year` query parameter is validated before use.
     * Previously `$request->get('year', ...)` accepted arbitrary values
     * (year=0, year=99999, year=foobar) without any bounds check.
     */
    public function giving(Request $request, string $id): JsonResponse
    {
        // HIGH-02 FIX: validate year before using it in date queries.
        $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $member = Member::query()->findOrFail($id);
        $year = $request->integer('year', (int) now()->format('Y'));

        $transactions = $member->transactions()
            ->where('type', 'income')
            ->whereYear('transaction_date', $year)
            ->with('category')
            ->orderByDesc('transaction_date')
            ->paginate(25);

        $byCategory = $transactions
            ->getCollection()
            ->groupBy(fn ($t) => $t->category?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'category' => $group->first()?->category?->name ?? 'Uncategorised',
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
                'year' => $year,
                'available_years' => $availableYears,
                'total' => round($transactions->sum('amount'), 2),
                'by_category' => $byCategory,
                'transactions' => TransactionResource::collection($transactions),
                'meta' => [
                    'total' => $transactions->total(),
                    'per_page' => $transactions->perPage(),
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'from' => $transactions->firstItem(),
                    'to' => $transactions->lastItem(),
                ],
                'links' => [
                    'prev' => $transactions->previousPageUrl(),
                    'next' => $transactions->nextPageUrl(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/members/{id}/giving-statement
     *
     * HIGH-02 FIX: year parameter validated, consistent with giving().
     */
    public function givingStatement(Request $request, string $id): mixed
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $member = Member::query()->with('branch')->findOrFail($id);
        $year = $request->integer('year', (int) now()->format('Y'));

        $transactions = $member->transactions()
            ->where('type', 'income')
            ->whereYear('transaction_date', $year)
            ->with('category')
            ->orderBy('transaction_date')
            ->get();

        $byCategory = $transactions
            ->groupBy(fn ($t) => $t->category?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'category' => $group->first()?->category?->name ?? 'Uncategorised',
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

    public function promoteToLeader(Request $request, string $id): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        $member = Member::query()->findOrFail($id);

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
        $unit = $unitClass::query()->findOrFail($data['target_id']);

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
            'data' => ['user_id' => $user->id, 'role' => $roleName, 'unit' => ['id' => $unit->id, 'name' => $unit->name]],
            'temp_password' => $tempPassword,
        ], 201);
    }

    public function createLogin(Request $request, string $id): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        $member = Member::query()->findOrFail($id);

        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users,email', 'max:150'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        if (User::where('member_id', $member->id)->exists()) {
            return response()->json(['message' => 'This member already has a user account.'], 422);
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
