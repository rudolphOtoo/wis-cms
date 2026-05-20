<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $branch = DB::table('branches')->first();

        $admin = User::create([
            'branch_id' => $branch->id,
            'name' => 'System Administrator',
            'email' => 'admin@wis-cms.local',
            'password' => bcrypt('Admin@12345'),
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');
    }
}
