<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            ['name' => 'view payments', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'manage payments', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Assign permissions to roles via the pivot table.
        $roles = DB::table('roles')->whereIn('name', ['super_admin', 'finance_officer', 'pastor', 'secretary'])->get();
        $permIds = DB::table('permissions')->whereIn('name', ['view payments', 'manage payments'])->pluck('id', 'name');

        foreach ($roles as $role) {
            $rows = [];

            // Everyone in the list gets view payments.
            $rows[] = [
                'role_id' => $role->id,
                'permission_id' => $permIds['view payments'],
            ];

            // finance_officer and super_admin also get manage payments.
            if (in_array($role->name, ['super_admin', 'finance_officer'])) {
                $rows[] = [
                    'role_id' => $role->id,
                    'permission_id' => $permIds['manage payments'],
                ];
            }

            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        DB::table('role_has_permissions')->whereIn('permission_id', function ($query) {
            $query->select('id')->from('permissions')->whereIn('name', ['view payments', 'manage payments']);
        })->delete();

        DB::table('permissions')->whereIn('name', ['view payments', 'manage payments'])->delete();
    }
};
