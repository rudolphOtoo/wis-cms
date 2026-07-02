<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ExportRoleController extends Controller
{
    /**
     * Export permissions and roles to JSON format.
     */
    public function exportPermissionsAndRoles(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only system admins can export roles and permissions
        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $format = $request->input('format', 'json'); // json or csv

        // Get all permissions and roles
        $permissions = Permission::all();
        $roles = Role::with('permissions')->get();

        $exportData = [
            'exported_at' => now()->toIso8601String(),
            'user_email' => $user->email,
            'permissions' => $permissions->map(function ($permission) {
                return [
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                    'description' => $permission->description,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ];
            }),
            'roles' => $roles->map(function ($role) {
                return [
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'description' => $role->description,
                    'permissions' => $role->permissions->pluck('name')->toArray(),
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            }),
        ];

        $filename = 'roles_and_permissions_export_'.now()->format('Ymd_His').'.json';
        $path = 'exports/roles_and_permissions/'.$filename;

        // Create directory if it doesn't exist
        Storage::makeDirectory('exports/roles_and_permissions');

        // Save to file
        Storage::put("exports/roles_and_permissions/{$filename}", json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return response()->json([
            'message' => 'Roles and permissions exported successfully',
            'data' => [
                'filename' => $filename,
                'path' => $path,
                'format' => $format,
                'permissions_count' => count($permissions),
                'roles_count' => count($roles),
                'exported_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Import permissions and roles from JSON file.
     */
    public function importPermissionsAndRoles(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only system admins can import roles and permissions
        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:json',
        ]);

        try {
            $file = $request->file('file');
            $content = file_get_contents($file->getRealPath());

            $importData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'message' => 'Invalid JSON file format',
                ], 400);
            }

            // Validate the imported data structure
            if (! isset($importData['permissions']) || ! isset($importData['roles'])) {
                return response()->json([
                    'message' => 'Invalid export file format',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Update permissions
                foreach ($importData['permissions'] as $permissionData) {
                    Permission::updateOrCreate(
                        ['name' => $permissionData['name'], 'guard_name' => $permissionData['guard_name'] ?? 'sanctum'],
                        [
                            'description' => $permissionData['description'] ?? null,
                        ]
                    );
                }

                // Update roles
                foreach ($importData['roles'] as $roleData) {
                    $role = Role::updateOrCreate(
                        ['name' => $roleData['name'], 'guard_name' => $roleData['guard_name'] ?? 'sanctum'],
                        [
                            'description' => $roleData['description'] ?? null,
                        ]
                    );

                    if (isset($roleData['permissions'])) {
                        $role->syncPermissions($roleData['permissions']);
                    }
                }

                DB::commit();

                return response()->json([
                    'message' => 'Roles and permissions imported successfully',
                    'data' => [
                        'permissions_imported' => count($importData['permissions']),
                        'roles_imported' => count($importData['roles']),
                        'imported_at' => now()->toIso8601String(),
                    ],
                ]);

            } catch (Exception $e) {
                DB::rollBack();

                throw $e;
            }

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to import roles and permissions: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download exported roles and permissions file.
     */
    public function downloadExportedFile(Request $request, string $filename): JsonResponse
    {
        $user = $request->user();

        // Only system admins can download files
        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $path = "exports/roles_and_permissions/{$filename}";

        if (! Storage::exists($path)) {
            return response()->json([
                'message' => 'File not found',
            ], 404);
        }

        return response()->file(Storage::path($path), [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    /**
     * Get export history (list of exported files)
     */
    public function getExportHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $files = Storage::files('exports/roles_and_permissions');
        $exportFiles = [];

        foreach ($files as $file) {
            if (Str::endsWith($file, '.json')) {
                $lastModified = Storage::lastModified($file);
                $exportFiles[] = [
                    'filename' => basename($file),
                    'path' => $file,
                    'last_modified' => $lastModified,
                    'size' => Storage::size($file),
                ];
            }
        }

        usort($exportFiles, function ($a, $b) {
            return strtotime($b['last_modified']) - strtotime($a['last_modified']);
        });

        return response()->json([
            'data' => [
                'exports' => $exportFiles,
                'total_exports' => count($exportFiles),
            ],
        ]);
    }

    /**
     * Clean up old export files
     */
    public function cleanupOldExports(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('system_admin')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $cutoffDate = now()->subDays(30);
        $files = Storage::files('exports/roles_and_permissions');
        $deletedFiles = [];

        foreach ($files as $file) {
            if (Str::endsWith($file, '.json')) {
                $lastModified = Carbon::create(Storage::lastModified($file));

                if ($lastModified->lt($cutoffDate)) {
                    Storage::delete($file);
                    $deletedFiles[] = basename($file);
                }
            }
        }

        return response()->json([
            'message' => 'Cleanup completed',
            'data' => [
                'deleted_files' => $deletedFiles,
                'total_deleted' => count($deletedFiles),
                'cleanup_date' => now()->toIso8601String(),
            ],
        ]);
    }
}
