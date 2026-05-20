<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('branches')->insert([
            'id' => Str::uuid(),
            'name' => 'Wesleyan International Society',
            'location' => 'Kumasi, Ghana',
            'address' => null,
            'phone' => null,
            'email' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
