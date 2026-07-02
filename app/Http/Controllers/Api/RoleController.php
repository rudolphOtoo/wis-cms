<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * Display a listing of the roles.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // System admins can view all roles
        if ($user->hasRole('system_admin')) {
            $roles = Role::withCount('users')->get();
        } else {
            // Other roles can only view their assigned roles
            $roles = $user->roles()->withCount('users')->get();
        }

        return response()->json([
            'data' => $roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'description' => $role->description,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                    'users_count' => $role->users_count ?? 0,
                ];
            }),
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only system admins can create roles
        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'guard_name' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        // Validate that permissions exist
        $permissions = Permission::whereIn('name', $validated['permissions'] ?? [])->get();

        DB::beginTransaction();

        try {
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => $validated['guard_name'],
                'description' => $validated['description'] ?? null,
            ]);

            if (! empty($validated['permissions'])) {
                $role->syncPermissions($permissions);
            }

            DB::commit();

            return response()->json([
                'message' => 'Role created successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'description' => $role->description,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ],
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create role: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified role.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $role = Role::findOrFail($id);

        // Check access based on user role
        if ($user->hasRole('system_admin')) {
            // System admins can view any role
            $role->load(['permissions', 'users']);
        } elseif ($user->hasRole('pastor') && $user->branch_id) {
            // Pastors can only view their branch's roles if any
            $role->load(['permissions', 'users']);
        } elseif ($user->hasRole('department_leader')) {
            // Department leaders can only view their own role
            if (! $role->users()->where('id', $user->id)->exists()) {
                return response()->json([
                    'message' => 'Unauthorized access to this role',
                ], 403);
            }
            $role->load(['permissions', 'users']);
        } else {
            // Other roles cannot view this role
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'description' => $role->description,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                        'description' => $permission->description,
                    ];
                }),
                'users' => $role->users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'branch_id' => $user->branch_id,
                    ];
                }),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $role = Role::findOrFail($id);

        // Only system admins can update roles
        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:roles,name,'.$id],
            'guard_name' => ['sometimes', 'required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        DB::beginTransaction();

        try {
            $role->update(array_filter($validated, fn ($key) => in_array($key, ['name', 'guard_name', 'description'])));

            if (isset($validated['permissions'])) {
                $permissions = Permission::whereIn('name', $validated['permissions'])->get();
                $role->syncPermissions($permissions);
            }

            DB::commit();

            return response()->json([
                'message' => 'Role updated successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'description' => $role->description,
                ],
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update role: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $role = Role::findOrFail($id);

        // Only system admins can delete roles
        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        // Prevent deletion if role has users assigned
        if ($role->users()->exists()) {
            return response()->json([
                'message' => 'Cannot delete role with assigned users',
            ], 409);
        }

        try {
            $role->delete();

            return response()->json([
                'message' => 'Role deleted successfully',
            ]);

        } catch (Exceptionólico $e) {
            return response()->json([
                'message' => 'Failed to delete role: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign users to a role.
     */
    public function assignUsers(Request $request, string $roleId): JsonResponse
    {
        $user = $request->user();

        // Only system admins can assign users to roles
        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validated = $request->validate([
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['required', 'string'],
        ]);

        $role = Role::findOrFail($roleId);

        try {
            $users = User::whereIn('id', $validated['user_ids'])->get();

            $role->users()->sync($users->pluck('id')->toArray());

            return response()->json([
                'message' => 'Users assigned to role successfully',
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'assigned_users' => $users->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                        ];
                    }),
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to assign users to role: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove users from a role.
     */
    public function removeUsers(Request $request, string $roleId): JsonResponse
    {
        $user = $request->user();

        // Only system admins can remove users from roles
        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validated = $request->validate([
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['required', 'string'],
        ]);

        $role = Role::findOrFail($roleId);

        try {
            $users = User::whereIn('id', $validated['user_ids'])->get();

            $role->users()->detach($users->pluck('id')->toArray());

            return response()->json([
                'message' => 'Users removed from role successfully',
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'removed_users' => $users->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                        ];
                    }),
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to remove users from role: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get permissions for a specific role.
     */
    public function getRolePermissions(Request $request, string $roleId): JsonResponse
    {
        $user = $request->user();
        $role = Role::findOrFail($roleId);

        // Check access
        if ($user->hasRole('system_admin') || $role->users()->where('id', $user->id)->exists()) {
            $permissions = $role->permissions;

            return response()->json([
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'permissions' => $permissions->map(function ($permission) {
                        return [
                            'name' => $permission->name,
                            'guard_name' => $permission->guard_name,
                            'description' => $permission->description,
                        ];
                    }),
                ],
            ]);
        }

        return response()->json([
            'message' => 'Unauthorized access',
        ], 403);
    }

    /**
     * Get users for a specific role (for UI selection)
     */
    public function getRoleUsers(Request $request, string $roleId): JsonResponse
    {
        $user = $request->user();
        $role = Role::findOrFail($roleId);

        // Check access
        if ($user->hasRole('system_admin') || $role->users()->where('id', $user->id)->exists()) {
            $users = $role->users()->get();

            return response()->json([
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'users' => $users->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'branch_id' => $user->branch_id,
                        ];
                    }),
                ],
            ]);
        }

        return response()->json([
            'message' => 'Unauthorized access',
        ], 403);
    }

    /**
     * Get all permissions for UI forms
     */
    public function getAllPermissions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $permissions = Permission::all();

        return response()->json([
            'data' => [
                'permissions' => $permissions->map(function ($permission) {
                    return [
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                        'description' => $permission->description,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Get role summary statistics
     */
    public function getRoleStats(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Role::query();

        // If user is not system_admin, only show relevant stats
        if ($user->hasRole('system_admin')) {
            $roles = $query->withCount('users')->get();
        } else {
            // For non-system admins, only show roles they have access to
            $userRoles = $user->roles()->get();
            $roles = $query->whereIn('id', $userRoles->pluck('id'))->withCount('users')->get();
        }

        return response()->json([
            'data' => [
                'roles' => $roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'users_count' => $role->users_count ?? 0,
                    ];
                }),
                'total_roles' => $roles->count(),
                'user_roles_count' => $user->roles()->count(),
            ],
        ]);
    }

    /**
     * Bulk import roles with permissions
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validated = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['required', 'array', 'keys' => ['name', 'guard_name', 'description', 'permissions']],
        ]);

        DB::beginTransaction();

        try {
            $imported = [];
            $errors = [];

            foreach ($validated['roles'] as $roleData) {
                try {
                    $permissions = Permission::whereIn('name', $roleData['permissions'] ?? [])->get();

                    $role = Role::updateOrCreate([
                        'name' => $roleData['name'],
                        'guard_name' => $roleData['guard_name'] ?? 'sanctum',
                        'description' => $roleData['description'] ?? null,
                    ]);

                    if (! empty($roleData['permissions'])) {
                        $role->syncPermissions($permissions);
                    }

                    $imported[] = [
                        'id' => $role->id,
                        'name' => $role->name,
                        'guard_name' => $role->guard_name,
                    ];
                } catch (Exception $e) {
                    $errors[] = [
                        'name' => $roleData['name'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Bulk import completed',
                'data' => [
                    'imported' => $imported,
                    'errors' => $errors,
                    'summary' => [
                        'total' => count($validated['roles']),
                        'success' => count($imported),
                        'failed' => count($errors),
                    ],
                ],
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to bulk import roles: '.$e->getMessage(),
            ], 500);
        }
    }
}
