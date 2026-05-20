<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Tithe',            'type' => 'income',  'description' => 'Individual tithe contributions'],
            ['name' => 'Sunday Offering',  'type' => 'income',  'description' => 'General Sunday service collection'],
            ['name' => 'Special Offering', 'type' => 'income',  'description' => 'Offerings for specific purposes'],
            ['name' => 'Harvest',          'type' => 'income',  'description' => 'Annual harvest contributions'],
            ['name' => 'Donation',         'type' => 'income',  'description' => 'General donations'],
            ['name' => 'Fundraising',      'type' => 'income',  'description' => 'Income from fundraising events'],
            ['name' => 'Building Fund',    'type' => 'income',  'description' => 'Contributions towards building projects'],
            ['name' => 'Welfare Fund',     'type' => 'income',  'description' => 'Contributions to the welfare fund'],
            ['name' => 'Other Income',     'type' => 'income',  'description' => 'Miscellaneous income'],
            ['name' => 'Utilities',        'type' => 'expense', 'description' => 'Electricity, water, and other utilities'],
            ['name' => 'Maintenance',      'type' => 'expense', 'description' => 'Building and equipment maintenance'],
            ['name' => 'Stationery',       'type' => 'expense', 'description' => 'Office and administrative supplies'],
            ['name' => 'Events',           'type' => 'expense', 'description' => 'Event planning and execution costs'],
            ['name' => 'Welfare',          'type' => 'expense', 'description' => 'Member welfare and support payments'],
            ['name' => 'Salaries',         'type' => 'expense', 'description' => 'Staff and worker remuneration'],
            ['name' => 'Transport',        'type' => 'expense', 'description' => 'Travel and transport costs'],
            ['name' => 'Communication',    'type' => 'expense', 'description' => 'Phone, SMS, and internet costs'],
            ['name' => 'Outreach',         'type' => 'expense', 'description' => 'Evangelism and outreach activities'],
            ['name' => 'Other Expense',    'type' => 'expense', 'description' => 'Miscellaneous expenses'],
        ];

        foreach ($categories as $category) {
            DB::table('finance_categories')->insert([
                'id' => Str::uuid(),
                'name' => $category['name'],
                'type' => $category['type'],
                'description' => $category['description'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
