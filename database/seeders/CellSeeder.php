<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Cell;
use App\Models\User;
use Illuminate\Database\Seeder;

class CellSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::first();
        if (! $branch) {
            $this->command->warn('No branch found — skipping CellSeeder.');

            return;
        }

        // The 7 official church cells: Peace, Faithfulness, Patience 1,
        // Patience 2 (led by Evander), Joy, Love, and Children Ministry.
        $evanderId = User::where('email', 'like', '%evander%')
            ->orWhere('name', 'like', '%Evander%')
            ->value('id');

        $cellDefs = [
            ['name' => 'Peace',             'description' => 'Cell group'],
            ['name' => 'Faithfulness',      'description' => 'Cell group'],
            ['name' => 'Patience 1',        'description' => 'Cell group'],
            ['name' => 'Patience 2',        'description' => 'Cell group',      'leader_user_id' => $evanderId],
            ['name' => 'Joy',               'description' => 'Cell group'],
            ['name' => 'Love',              'description' => 'Cell group'],
            ['name' => 'Children Ministry', 'description' => 'Children service attendance'],
        ];

        $cells = [];
        foreach ($cellDefs as $def) {
            $cells[] = Cell::firstOrCreate(
                ['branch_id' => $branch->id, 'name' => $def['name']],
                [
                    'description' => $def['description'],
                    'leader_user_id' => $def['leader_user_id'] ?? null,
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('CellSeeder: '.count($cells).' cells created (no members assigned).');
    }
}
