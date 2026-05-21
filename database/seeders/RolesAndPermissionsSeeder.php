<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view members', 'create members', 'edit members', 'delete members', 'export members',
            'view children', 'create children', 'edit children', 'delete children',
            'view visitors', 'create visitors', 'edit visitors', 'delete visitors',
            'view attendance', 'create attendance', 'edit attendance', 'delete attendance',
            'view departments', 'create departments', 'edit departments', 'delete departments',
            'manage department members',
            'view finance', 'create transactions', 'edit transactions', 'delete transactions', 'export finance',
            'view messages', 'send messages',
            'view reports', 'export reports',
            'manage users', 'manage roles', 'manage branches', 'manage service types',
            'manage finance categories', 'view audit log',
            'access portal',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdmin->syncPermissions(Permission::all());

        $pastor = Role::firstOrCreate(['name' => 'pastor']);
        $pastor->syncPermissions([
            'view members', 'view children', 'view visitors', 'view attendance',
            'view departments', 'view finance', 'view messages', 'view reports',
            'export reports', 'export members', 'export finance',
        ]);

        $secretary = Role::firstOrCreate(['name' => 'secretary']);
        $secretary->syncPermissions([
            'view members', 'create members', 'edit members', 'export members',
            'view children', 'create children', 'edit children',
            'view visitors', 'create visitors', 'edit visitors',
            'view attendance', 'create attendance', 'edit attendance',
            'view departments', 'create departments', 'edit departments',
            'manage department members',
            'view messages', 'send messages',
            'view reports', 'export reports',
        ]);

        $finance = Role::firstOrCreate(['name' => 'finance_officer']);
        $finance->syncPermissions([
            'view finance', 'create transactions', 'edit transactions',
            'delete transactions', 'export finance',
            'view members', 'view reports', 'export reports',
        ]);

        $deptLeader = Role::firstOrCreate(['name' => 'department_leader']);
        $deptLeader->syncPermissions([
            'view members', 'view departments', 'manage department members',
            'view attendance', 'create attendance', 'view reports',
        ]);

        $usher = Role::firstOrCreate(['name' => 'usher']);
        $usher->syncPermissions([
            'view members', 'view children', 'create attendance', 'view attendance',
        ]);

        $member = Role::firstOrCreate(['name' => 'member']);
        $member->syncPermissions(['access portal']);
    }
}
