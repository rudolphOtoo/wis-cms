<?php

namespace Database\Seeders;

use App\Diocese\Diocese;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeSeeder extends Seeder
{
    /**
     * Seed the standard service/meeting types from the active profile.
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
        $services = Diocese::referenceData('service_types', []);

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
