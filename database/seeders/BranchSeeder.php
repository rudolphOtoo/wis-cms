<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Seed the church's branch row.
     *
     * Idempotent: re-running this seeder is safe. It matches on
     * the canonical branch name. If a branch with that name already
     * exists, attributes are left untouched (production may have
     * edited address/phone/email via the UI; we don't clobber).
     *
     * For multi-branch Methodist Ghana deploys, customize CHURCH_NAME
     * in .env or duplicate this seeder per branch.
     */
    public function run(): void
    {
        $churchName = env('CHURCH_NAME', 'Wesleyan International Society');

        $branch = Branch::firstOrCreate(
            ['name' => $churchName],
            [
                'location' => env('CHURCH_LOCATION', 'Kumasi, Ghana'),
                'address' => null,
                'phone' => null,
                'email' => null,
                'is_active' => true,
            ]
        );

        $this->command->info("  ✓ Branch ready: {$branch->name}");
    }
}
