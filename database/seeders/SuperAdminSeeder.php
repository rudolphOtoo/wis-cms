<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure a branch exists — create a fallback if none does.
        //    Prevents the "read property id on null" crash.
        $branch = Branch::first() ?? Branch::create([
            'name'      => 'Wesleyan International Society',
            'location'  => 'Accra',
            'address'   => 'Accra, Ghana',
            'phone'     => '0000000000',
            'email'     => 'info@wis-cms.local',
            'is_active' => true,
        ]);

        // 2. Ensure the super_admin role exists before assigning it.
        //    Prevents a crash if roles weren't seeded first.
        if (! Role::where('name', 'super_admin')->exists()) {
            $this->command->warn('  ⚠ super_admin role missing — run RolesAndPermissionsSeeder first. Skipping role assignment.');
        }

        // 3. firstOrCreate makes this idempotent — safe to run repeatedly.
        //    On re-run it finds the existing admin instead of crashing on duplicate email.
        $admin = User::firstOrCreate(
            ['email' => 'admin@wis-cms.local'],
            [
                'branch_id' => $branch->id,
                'name'      => 'System Administrator',
                'password'  => Hash::make('Admin@12345'),
                'is_active' => true,
            ]
        );

        // 4. Assign the role only if it exists and isn't already assigned.
        if (Role::where('name', 'super_admin')->exists() && ! $admin->hasRole('super_admin')) {
            $admin->assignRole('super_admin');
        }

        $this->command->info("  ✓ Super admin ready: {$admin->email}");
    }
}
