<?php

namespace Database\Seeders;

use App\Diocese\Diocese;
use App\Models\Branch;
use App\Models\Cell;
use App\Models\User;
use Illuminate\Database\Seeder;

class CellSeeder extends Seeder
{
    /**
     * Seed cells from the active profile.
     *
     * The profile declares which cells (if any) a diocese organises its
     * attendance around. The default WIS profile seeds the 7 official
     * cells (incl. the Children Ministry cell); a headcount diocese that
     * is not cell-based declares an empty list.
     */
    public function run(): void
    {
        $branch = Branch::first();
        if (! $branch) {
            $this->command->warn('No branch found — skipping CellSeeder.');

            return;
        }

        $cellDefs = Diocese::referenceData('cells', []);

        foreach ($cellDefs as $def) {
            $leaderUserId = null;
            if (! empty($def['leader_user_email'])) {
                $leaderUserId = User::where('email', 'like', $def['leader_user_email'])
                    ->value('id');
            }

            Cell::firstOrCreate(
                ['branch_id' => $branch->id, 'name' => $def['name']],
                [
                    'description' => $def['description'] ?? 'Cell group',
                    'leader_user_id' => $leaderUserId,
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('CellSeeder: '.count($cellDefs).' cells created (no members assigned).');
    }
}
