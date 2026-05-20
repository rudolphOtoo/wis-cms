<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'Sunday Adult Service',   'slug' => 'sunday_adult',    'type' => 'adult',    'description' => 'Main Sunday worship service for adults'],
            ['name' => 'Sunday Children Service', 'slug' => 'sunday_children', 'type' => 'children', 'description' => 'Sunday service for children'],
            ['name' => 'Bible Study',            'slug' => 'bible_study',     'type' => 'combined', 'description' => 'Midweek Bible study session'],
            ['name' => 'Prayer Meeting',         'slug' => 'prayer_meeting',  'type' => 'combined', 'description' => 'Weekly prayer and intercession meeting'],
            ['name' => 'Special Service',        'slug' => 'special_service', 'type' => 'combined', 'description' => 'Special events and services'],
        ];

        foreach ($services as $service) {
            DB::table('service_types')->insert([
                'id' => Str::uuid(),
                'name' => $service['name'],
                'slug' => $service['slug'],
                'type' => $service['type'],
                'description' => $service['description'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
