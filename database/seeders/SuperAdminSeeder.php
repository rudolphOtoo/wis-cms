<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    /**
     * Seed the super admin user.
     *
     * Production: reads ADMIN_EMAIL and ADMIN_PASSWORD from .env.
     * If either is missing, the seeder skips with a warning — fail
     * safe rather than create a known-credential admin.
     *
     * Development: if .env doesn't define ADMIN_EMAIL, falls back to
     * 'admin@wis-cms.local' / 'Admin@12345' to keep the demo flow
     * working. The fallback ONLY engages when APP_ENV=local.
     *
     * Idempotent: firstOrCreate matches by email. If the admin user
     * already exists, attributes (including password) are NOT updated
     * - that protects against accidentally resetting a production
     * password by re-running seeders.
     */
    public function run(): void
    {
        $isLocal = app()->environment('local');
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        // Development fallback — keeps the demo working out of the box.
        // Production: ADMIN_EMAIL + ADMIN_PASSWORD must be set in .env.
        if (empty($email) || empty($password)) {
            if ($isLocal) {
                $email = $email ?: 'admin@wis-cms.local';
                $password = $password ?: 'Admin@12345';
                $this->command->warn('  ⚠ Using development fallback admin credentials. Set ADMIN_EMAIL and ADMIN_PASSWORD in .env for production.');
            } else {
                $this->command->error('  ✗ ADMIN_EMAIL and ADMIN_PASSWORD must be set in .env for production. Skipping super admin creation.');

                return;
            }
        }

        // 1. Ensure a branch exists — create a fallback if none does.
        $branch = Branch::first() ?? Branch::create([
            'name' => env('CHURCH_NAME', 'Wesleyan International Society'),
            'location' => env('CHURCH_LOCATION', 'Accra'),
            'address' => null,
            'phone' => null,
            'email' => null,
            'is_active' => true,
        ]);

        // 2. Warn if super_admin role missing (RolesAndPermissionsSeeder
        //    should have created it first; deploy order matters).
        if (! Role::where('name', 'super_admin')->exists()) {
            $this->command->warn('  ⚠ super_admin role missing — run RolesAndPermissionsSeeder first. Skipping role assignment.');
        }

        // 3. firstOrCreate makes this idempotent. The password is ONLY
        //    set on initial creation — re-running the seeder will not
        //    overwrite a password that was rotated in production.
        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'branch_id' => $branch->id,
                'name' => 'System Administrator',
                'password' => Hash::make($password),
                'is_active' => true,
            ]
        );

        // 4. Assign role only if it exists and isn't already assigned.
        if (Role::where('name', 'super_admin')->exists() && ! $admin->hasRole('super_admin')) {
            $admin->assignRole('super_admin');
        }

        $this->command->info("  ✓ Super admin ready: {$admin->email}");
    }
}
