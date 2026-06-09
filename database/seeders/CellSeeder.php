<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Cell;
use App\Models\Member;
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

        // Pick a few real users to act as cell leaders (best-effort).
        $leaders = User::where('branch_id', $branch->id)->pluck('id')->all();
        $pickLeader = fn ($i) => $leaders[$i % max(count($leaders), 1)] ?? null;

        // The church names cells geographically, by special name, and by
        // age group — seed a realistic mix of all three.
        $cellDefs = [
            ['name' => 'Dansoman Cell',        'description' => 'Geographic — members in the Dansoman area.'],
            ['name' => 'Spintex Cell',         'description' => 'Geographic — members along the Spintex road.'],
            ['name' => 'Tema Community Cell',  'description' => 'Geographic — members in Tema.'],
            ['name' => 'Bethel Fellowship',    'description' => 'A special-name cell for committed intercessors.'],
            ['name' => 'Young Adults (18–35)', 'description' => 'Age group — young working adults and students.'],
            ['name' => 'Senior Saints (60+)',  'description' => 'Age group — our cherished elders.'],
        ];

        $cells = [];
        foreach ($cellDefs as $i => $def) {
            $cells[] = Cell::firstOrCreate(
                ['branch_id' => $branch->id, 'name' => $def['name']],
                [
                    'description' => $def['description'],
                    'leader_user_id' => $pickLeader($i),
                    'is_active' => true,
                ]
            );
        }

        // Distribute active members across cells in UNEVEN, realistic
        // sizes, leaving a handful unassigned (not everyone is placed yet).
        $active = Member::where('branch_id', $branch->id)
            ->where('status', 'active')
            ->whereNull('cell_id')
            ->get();

        // Realistic uneven distribution; remaining members stay unassigned.
        $distribution = [11, 9, 8, 7, 6, 4]; // sums to 45 of ~47 → ~2 unassigned
        $cursor = 0;
        foreach ($cells as $i => $cell) {
            $take = $distribution[$i] ?? 0;
            $slice = $active->slice($cursor, $take);
            foreach ($slice as $member) {
                $member->update(['cell_id' => $cell->id]);
            }
            $cursor += $take;
        }

        // Actual count (cursor was the planned distribution, not what was assigned).
        // CellSeeder runs before DemoDataSeeder so on a fresh DB there are no
        // members yet — distribution will run again from DemoDataSeeder.
        $assigned = Member::where('branch_id', $branch->id)->whereNotNull('cell_id')->count();
        $this->command->info("CellSeeder: {$assigned} of ".$active->count().' available members assigned across '.count($cells).' cells.');
    }
}
