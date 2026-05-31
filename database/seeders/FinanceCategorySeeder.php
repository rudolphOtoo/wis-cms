<?php

namespace Database\Seeders;

use App\Models\FinanceCategory;
use Illuminate\Database\Seeder;

/**
 * WIS Methodist Ghana finance categories.
 *
 * INCOME list and order are dictated by the council. display_order
 * drives the dropdown so secretaries see Tithe and Offertory first
 * (the high-frequency categories), with less-common ones below.
 *
 * "Welfare" deliberately appears as BOTH income (members contribute
 * to the fund) and expense (church disburses to members in need) -
 * standard double-entry pattern. The UNIQUE (name, type) constraint
 * permits this.
 */
class FinanceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $income = [
            ['name' => 'Tithe',                              'description' => 'Individual tithe contributions'],
            ['name' => 'Offertory',                          'description' => 'General Sunday offertory collection'],
            ['name' => 'Sunday School Offertory',            'description' => 'Sunday school class offerings'],
            ['name' => 'Methodist Development Fund (MDF)',   'description' => 'Connexional development levy'],
            ['name' => 'Welfare',                            'description' => 'Welfare fund contributions from members'],
            ['name' => 'Thanksgiving',                       'description' => 'Thanksgiving offerings'],
            ['name' => 'Day Born Offering',                  'description' => 'Day-born / birthday offerings'],
            ['name' => 'Scholarship Fund',                   'description' => 'Scholarship and education support contributions'],
            ['name' => 'Pledges Redemption',                 'description' => 'Redemption of pledges made'],
            ['name' => 'Others',                             'description' => 'Other miscellaneous income'],
        ];

        foreach ($income as $i => $row) {
            FinanceCategory::updateOrCreate(
                ['name' => $row['name'], 'type' => 'income'],
                [
                    'description' => $row['description'],
                    'is_active' => true,
                    'display_order' => $i + 1,
                ]
            );
        }

        // EXPENSE - preserve existing categories. Council added "Allowance"
        // (paid OUT to clergy/staff). Others kept as-is for now; ordering
        // chosen so the council-added Allowance sits at top and existing
        // ones follow alphabetically afterward.
        $expense = [
            ['name' => 'Allowance',     'description' => 'Allowances paid to clergy and staff',          'order' => 1],
            ['name' => 'Communication', 'description' => 'Phone, SMS, and internet costs',               'order' => 10],
            ['name' => 'Events',        'description' => 'Event planning and execution costs',           'order' => 11],
            ['name' => 'Maintenance',   'description' => 'Building and equipment maintenance',           'order' => 12],
            ['name' => 'Other Expense', 'description' => 'Miscellaneous expenses',                       'order' => 13],
            ['name' => 'Outreach',      'description' => 'Evangelism and outreach activities',           'order' => 14],
            ['name' => 'Salaries',      'description' => 'Staff and worker remuneration',                'order' => 15],
            ['name' => 'Stationery',    'description' => 'Office and administrative supplies',           'order' => 16],
            ['name' => 'Transport',     'description' => 'Travel and transport costs',                   'order' => 17],
            ['name' => 'Utilities',     'description' => 'Electricity, water, and other utilities',      'order' => 18],
            ['name' => 'Welfare',       'description' => 'Member welfare and support payments',          'order' => 19],
        ];

        foreach ($expense as $row) {
            FinanceCategory::updateOrCreate(
                ['name' => $row['name'], 'type' => 'expense'],
                [
                    'description' => $row['description'],
                    'is_active' => true,
                    'display_order' => $row['order'],
                ]
            );
        }
    }
}
