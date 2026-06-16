<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeSeeder extends Seeder
{
    /**
     * Seed the standard service/meeting types.
     *
     * Idempotent: matches on slug (the stable unique key). Re-running
     * leaves existing rows untouched so production-edited descriptions
     * are preserved.
     *
     * STYLE: all slugs use underscores consistently. The two
     * meeting types (cell_meeting, department_meeting) were
     * originally hyphenated but were renamed to match the
     * convention via the 2026_06_02 rename migration.
     */
    public function run(): void
    {
        $services = [
            ['name' => 'Sunday Adult Service',    'slug' => 'sunday_adult',       'type' => 'adult',    'description' => 'Main Sunday worship service for adults'],
            ['name' => 'Sunday Children Service', 'slug' => 'sunday_children',    'type' => 'children', 'description' => 'Sunday service for children'],
            ['name' => 'Bible Study',             'slug' => 'bible_study',        'type' => 'combined', 'description' => 'Bible study session'],
            ['name' => 'Midweek Service',         'slug' => 'midweek_service',    'type' => 'combined', 'description' => 'Wednesday evening midweek worship service'],
            ['name' => 'Prayer Meeting',          'slug' => 'prayer_meeting',     'type' => 'combined', 'description' => 'Weekly prayer and intercession meeting'],
            ['name' => 'Special Service',         'slug' => 'special_service',    'type' => 'combined', 'description' => 'Special events and services'],
            ['name' => 'Cell Meeting',            'slug' => 'cell_meeting',       'type' => 'combined', 'description' => 'Weekly cell group fellowship meeting'],
            ['name' => 'Department Meeting',      'slug' => 'department_meeting', 'type' => 'combined', 'description' => 'Department-level meeting (Choir, Ushers, etc.)'],
        ];

        foreach ($services as $service) {
            ServiceType::firstOrCreate(
                ['slug' => $service['slug']],
                [
                    'name' => $service['name'],
                    'type' => $service['type'],
                    'description' => $service['description'],
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('  ✓ Service types ready: '.count($services).' types');
    }
}
