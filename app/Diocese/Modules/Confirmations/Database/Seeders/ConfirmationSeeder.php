<?php

namespace App\Diocese\Modules\Confirmations\Database\Seeders;

use App\Diocese\Modules\Confirmations\Models\Confirmation;
use App\Models\Member;
use Illuminate\Database\Seeder;

/**
 * Sample confirmations for the reference module.
 *
 * Dev/demo only — a diocese seeds its own confirmation history. Idempotent:
 * re-running never duplicates a (member_id, confirmed_at) pair.
 *
 * USAGE (module must be enabled in the profile)
 *   php artisan db:seed --class="App\Diocese\Modules\Confirmations\Database\Seeders\ConfirmationSeeder" --force
 */
class ConfirmationSeeder extends Seeder
{
    public function run(): void
    {
        $members = Member::query()->limit(20)->get();

        if ($members->isEmpty()) {
            $this->command->warn('No members to confirm — skipping.');

            return;
        }

        $now = now();
        $count = 0;

        foreach ($members as $index => $member) {
            $confirmedAt = $now->subDays(($index % 30) + 1)->toDateString();

            $count += Confirmation::query()->firstOrCreate(
                ['member_id' => $member->id, 'confirmed_at' => $confirmedAt],
                [
                    'officiating_clergy' => 'Rev. John Mensah',
                    'location' => 'Bethel Methodist Church',
                    'notes' => 'Sample confirmation record (module reference seed).',
                ]
            )->wasRecentlyCreated ? 1 : 0;
        }

        $this->command->info("  ✓ Seeded {$count} confirmation record(s).");
    }
}
