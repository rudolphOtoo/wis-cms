<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seeder. Run on every deploy to keep canonical
 * reference data in sync (roles, permissions, service types, finance
 * categories, branch, super admin).
 *
 * USAGE
 *   php artisan db:seed --class=ProductionSeeder --force
 *
 * Idempotent: each called seeder uses firstOrCreate / updateOrCreate
 * so re-running is safe. Existing rows are not duplicated or clobbered.
 *
 * EXCLUDED from this seeder (dev-only demo data):
 *   - DemoDataSeeder (members, attendance, transactions)
 *
 * For development with full demo data, use the default DatabaseSeeder
 * (php artisan db:seed) which calls everything including the dev-only
 * seeders.
 *
 * PREREQUISITES for production:
 *   - .env defines ADMIN_EMAIL, ADMIN_PASSWORD (used by SuperAdminSeeder)
 *   - .env optionally defines CHURCH_NAME, CHURCH_LOCATION (BranchSeeder)
 *   - See DEPLOYMENT.md for the full deploy sequence
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Running ProductionSeeder (canonical reference data only)...');

        $this->call([
            // Order matters: roles before super admin (so the role exists
            // when SuperAdminSeeder tries to assign it). Branch before
            // SuperAdmin (so the admin has a branch to belong to).
            BranchSeeder::class,
            RolesAndPermissionsSeeder::class,
            ServiceTypeSeeder::class,
            FinanceCategorySeeder::class,
            SuperAdminSeeder::class,
            CellSeeder::class,
        ]);

        $this->command->info('  ✓ Production seed complete.');
    }
}
