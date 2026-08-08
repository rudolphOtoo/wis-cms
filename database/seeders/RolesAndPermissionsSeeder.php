<?php

namespace Database\Seeders;

use App\Diocese\Diocese;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Permission list is the union of every permission granted to any
        // role (super_admin's '*' is excluded — it is a grant, not a name).
        $permissions = array_values(array_filter(
            Diocese::referenceData('permissions', []),
            fn (string $permission) => $permission !== '*',
        ));

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        foreach (Diocese::referenceData('roles', []) as $roleName => $config) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            if (($config['permissions'] ?? null) === '*') {
                $role->syncPermissions(Permission::all());
            } else {
                $role->syncPermissions($config['permissions'] ?? []);
            }
        }
    }
}
