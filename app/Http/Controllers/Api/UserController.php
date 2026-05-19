<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('branch_id', $request->user()->branch_id)
            ->with('roles');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name',  'ilike', "%{$search}%")
                  ->orWhere('email','ilike', "%{$search}%");
            });
        }

        if ($role = $request->get('role')) {
            $query->whereHas('roles', fn($q) => $q->where('name', $role));
        }

        $users = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => collect($users->items())->map(fn($u) => $this->transform($u)),
            'meta' => [
                'total'        => $users->total(),
                'per_page'     => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
            ],
        ]);
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::orderBy('name')->get()->map(fn($r) => [
                'name'        => $r->name,
                'label'       => ucwords(str_replace('_', ' ', $r->name)),
                'permissions' => $r->permissions->pluck('name'),
            ]),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create([
            'branch_id' => $request->user()->branch_id,
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $user->assignRole($request->role);

        activity()->causedBy($request->user())
                  ->performedOn($user)
                  ->log("Created user: {$user->name} with role {$request->role}");

        return response()->json([
            'message' => 'User created successfully.',
            'data'    => $this->transform($user->load('roles')),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = User::where('branch_id', $request->user()->branch_id)
            ->with('roles')
            ->findOrFail($id);

        return response()->json(['data' => $this->transform($user)]);
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = User::where('branch_id', $request->user()->branch_id)
            ->findOrFail($id);

        // Prevent admin from disabling their own account
        if ($user->id === $request->user()->id && $request->has('is_active') && !$request->boolean('is_active')) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $data = $request->only(['name', 'email', 'is_active']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        if ($request->has('role')) {
            $user->syncRoles([$request->role]);
        }

        activity()->causedBy($request->user())
                  ->performedOn($user)
                  ->log("Updated user: {$user->name}");

        return response()->json([
            'message' => 'User updated successfully.',
            'data'    => $this->transform($user->fresh('roles')),
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

    protected function transform(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'is_active'     => $user->is_active,
            'last_login_at' => $user->last_login_at?->diffForHumans(),
            'role'          => $user->roles->first()?->name,
            'role_label'    => $user->roles->first() ? ucwords(str_replace('_', ' ', $user->roles->first()->name)) : null,
            'created_at'    => $user->created_at->format('Y-m-d'),
        ];
    }
}
