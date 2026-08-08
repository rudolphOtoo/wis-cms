<?php

namespace Database\Seeders;

use App\Diocese\Diocese;
use App\Models\FinanceCategory;
use Illuminate\Database\Seeder;

/**
 * Finance categories come from the active profile's reference_data.
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
        $categories = Diocese::referenceData('finance_categories', []);

        foreach (($categories['income'] ?? []) as $i => $row) {
            FinanceCategory::updateOrCreate(
                ['name' => $row['name'], 'type' => 'income'],
                [
                    'description' => $row['description'],
                    'is_active' => true,
                    'display_order' => $i + 1,
                ]
            );
        }

        foreach (($categories['expense'] ?? []) as $row) {
            FinanceCategory::updateOrCreate(
                ['name' => $row['name'], 'type' => 'expense'],
                [
                    'description' => $row['description'],
                    'is_active' => true,
                    'display_order' => $row['order'] ?? 0,
                ]
            );
        }
    }
}
