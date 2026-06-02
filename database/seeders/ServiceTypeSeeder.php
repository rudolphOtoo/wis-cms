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
     * STYLE NOTE: slugs are inconsistent — most use underscores
     * (sunday_adult), but cell-meeting and department-meeting use
     * hyphens. The hyphenated pair was added later and didn't follow
     * the existing convention. They're in active use (8 dept sessions,
     * 4 cell sessions in dev). A future cleanup task should rename
     * them to underscores via a migration; not done here to keep
     * production-readiness scope contained.
     */
    public function run(): void
    {
        $services = [
            ['name' => 'Sunday Adult Service',    'slug' => 'sunday_adult',       'type' => 'adult',    'description' => 'Main Sunday worship service for adults'],
            ['name' => 'Sunday Children Service', 'slug' => 'sunday_children',    'type' => 'children', 'description' => 'Sunday service for children'],
            ['name' => 'Bible Study',             'slug' => 'bible_study',        'type' => 'combined', 'description' => 'Midweek Bible study session'],
            ['name' => 'Prayer Meeting',          'slug' => 'prayer_meeting',     'type' => 'combined', 'description' => 'Weekly prayer and intercession meeting'],
            ['name' => 'Special Service',         'slug' => 'special_service',    'type' => 'combined', 'description' => 'Special events and services'],
            ['name' => 'Cell Meeting',            'slug' => 'cell-meeting',       'type' => 'combined', 'description' => 'Weekly cell group fellowship meeting'],
            ['name' => 'Department Meeting',      'slug' => 'department-meeting', 'type' => 'combined', 'description' => 'Department-level meeting (Choir, Ushers, etc.)'],
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
