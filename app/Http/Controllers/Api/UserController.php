<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('branch_id', $request->user()->branch_id)
            ->with(['roles', 'member']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $users = $query->orderBy('name')->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            // PERF FIX: with('permissions') eager-loads the Spatie MorphToMany
            // pivot in a single JOIN query. Without it, $r->permissions fires
            // one SELECT per role (N+1 across 7 system roles).
            'data' => Role::with('permissions')->orderBy('name')->get()->map(fn ($r) => [
                'name' => $r->name,
                'label' => ucwords(str_replace('_', ' ', $r->name)),
                'permissions' => $r->permissions->pluck('name'),
            ]),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        // Auto-generate a strong temporary password; the admin hands it
        // to the user, who is forced to change it on first login
        // (must_change_password = true + EnsurePasswordChanged middleware).
        $tempPassword = Str::password(12);

        $user = DB::transaction(function () use ($request, $tempPassword) {
            $u = User::create([
                'branch_id' => $request->user()->branch_id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($tempPassword),
                'is_active' => $request->boolean('is_active', true),
                'must_change_password' => true,
                'member_id' => $request->input('member_id'),
            ]);
            $u->assignRole($request->role);

            return $u;
        });

        // Audit trail: log the issuance, NOT the password itself.
        activity()->causedBy($request->user())
            ->performedOn($user)
            ->log("Created user: {$user->name} with role {$request->role} (temporary password issued)");

        // The plain password is returned in this one response only —
        // never stored anywhere reversible, never logged.
        return response()->json([
            'message' => 'User created successfully.',
            'data' => new UserResource($user->load('roles')),
            'temp_password' => $tempPassword,
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = User::where('branch_id', $request->user()->branch_id)
            ->with(['roles', 'member'])
            ->findOrFail($id);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = User::where('branch_id', $request->user()->branch_id)
            ->findOrFail($id);

        // Prevent admin from disabling their own account
        if ($user->id === $request->user()->id && $request->has('is_active') && ! $request->boolean('is_active')) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $data = $request->only(['name', 'email', 'is_active']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        DB::transaction(function () use ($user, $data, $request): void {
            $user->update($data);

            if ($request->has('role')) {
                $user->syncRoles([$request->role]);
            }
        });

        activity()->causedBy($request->user())
            ->performedOn($user)
            ->log("Updated user: {$user->name}");

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => new UserResource($user->fresh('roles')),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $user = User::where('branch_id', $request->user()->branch_id)
            ->findOrFail($id);

        $name = $user->name;
        $user->delete();

        activity()->causedBy($request->user())
            ->log("Deleted user: {$name}");

        return response()->json(['message' => 'User deleted successfully.']);
    }

    /**
     * Link a User to an existing Member record.
     * Enforces: same-branch + UNIQUE constraint (one Member = one User).
     */
    public function linkMember(Request $request, string $id): JsonResponse
    {
        $user = User::where('branch_id', $request->user()->branch_id)->findOrFail($id);

        $data = $request->validate([
            'member_id' => ['required', 'uuid', 'exists:members,id'],
        ]);

        $member = Member::where('branch_id', $user->branch_id)
            ->findOrFail($data['member_id']);

        if ($user->member_id) {
            return response()->json([
                'message' => 'This user is already linked to a member. Unlink first.',
            ], 422);
        }

        if (User::where('member_id', $member->id)->exists()) {
            return response()->json([
                'message' => 'Another user is already linked to this member.',
            ], 422);
        }

        $user->update(['member_id' => $member->id]);

        activity()->causedBy($request->user())
            ->performedOn($user)
            ->log("Linked user {$user->name} to member {$member->first_name} {$member->last_name}");

        return response()->json([
            'message' => 'Member linked successfully.',
            'data' => new UserResource($user->fresh()->load('member')),
        ]);
    }

    /**
     * Create a new Member from form data AND link to this User in one
     * transaction. Useful when a leader exists as a User but has no
     * Member record yet.
     */
    public function createAndLinkMember(Request $request, string $id): JsonResponse
    {
        $user = User::where('branch_id', $request->user()->branch_id)->findOrFail($id);

        if ($user->member_id) {
            return response()->json([
                'message' => 'This user is already linked to a member.',
            ], 422);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['required', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ]);

        $member = DB::transaction(function () use ($user, $data) {
            $m = Member::create([
                ...$data,
                'branch_id' => $user->branch_id,
                'status' => 'active',
                'join_date' => now()->toDateString(),
            ]);

            $user->update(['member_id' => $m->id]);

            return $m;
        });

        activity()->causedBy($request->user())
            ->performedOn($user)
            ->log("Created member {$member->first_name} {$member->last_name} and linked to user {$user->name}");

        return response()->json([
            'message' => 'Member created and linked successfully.',
            'data' => new UserResource($user->fresh()->load('member')),
        ], 201);
    }

    /**
     * Unlink a User from their Member (sets member_id = NULL).
     * The Member record is preserved — only the link is severed.
     */
    public function unlinkMember(Request $request, string $id): JsonResponse
    {
        $user = User::where('branch_id', $request->user()->branch_id)->findOrFail($id);

        if (! $user->member_id) {
            return response()->json([
                'message' => 'This user is not linked to a member.',
            ], 422);
        }

        $memberName = $user->member?->first_name.' '.$user->member?->last_name;
        $user->update(['member_id' => null]);

        activity()->causedBy($request->user())
            ->performedOn($user)
            ->log("Unlinked user {$user->name} from member {$memberName}");

        return response()->json([
            'message' => 'Member unlinked successfully.',
            'data' => new UserResource($user->fresh()),
        ]);
    }
}
