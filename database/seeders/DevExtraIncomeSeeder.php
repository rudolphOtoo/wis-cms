<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * DEV-ONLY seeder: populates realistic transactions across the
 * 8 income categories that are otherwise empty in dev (Tithe and
 * Offertory have plenty of demo data from other seeders).
 *
 * WHY THIS EXISTS:
 * The Reports module needs varied data to be demoable. Without
 * spread across all categories, the income-by-category report
 * looks sparse (just Tithe + Offertory). This seeder adds ~60
 * transactions across the other 8 categories so reports render
 * meaningfully in dev.
 *
 * WHY NOT IN PRODUCTION:
 * This is fake demo data. Production deploys must NOT contain
 * fictitious transactions. ProductionSeeder explicitly excludes
 * this class. Run only via:
 *   php artisan db:seed --class=DevExtraIncomeSeeder
 *
 * IDEMPOTENT:
 * Each transaction uses a unique reference like "DEV-EXTRA-<cat>-<idx>"
 * and the seeder checks for existence before inserting. Safe to re-run.
 *
 * AUDIT:
 * All transactions are recorded_by the admin user; an activity log
 * entry summarises the seed at completion.
 */
class DevExtraIncomeSeeder extends Seeder
{
    /**
     * Realistic Methodist Ghana income patterns per category.
     *
     * Format: 'Category Name' => array of [amount_min, amount_max, frequency_weeks, description]
     */
    protected const PATTERNS = [
        'Sunday School Offertory' => ['range' => [30, 150],    'count' => 20, 'note' => 'Weekly Sunday school offering'],
        'Methodist Development Fund (MDF)' => ['range' => [200, 800],   'count' => 6,  'note' => 'Monthly MDF contribution'],
        'Welfare' => ['range' => [100, 500],   'count' => 5,  'note' => 'Welfare fund contribution'],
        'Thanksgiving' => ['range' => [50, 300],    'count' => 12, 'note' => 'Thanksgiving offering'],
        'Day Born Offering' => ['range' => [100, 1000],  'count' => 8,  'note' => 'Day-born offering'],
        'Scholarship Fund' => ['range' => [300, 2000],  'count' => 4,  'note' => 'Quarterly scholarship contribution'],
        'Pledges Redemption' => ['range' => [500, 3000],  'count' => 3,  'note' => 'Pledge redemption'],
        'Others' => ['range' => [50, 200],    'count' => 3,  'note' => 'Miscellaneous income'],
    ];

    public function run(): void
    {
        $branch = Branch::first();
        $admin = User::where('email', 'admin@wis-cms.local')->first();

        if (! $branch || ! $admin) {
            $this->command->error('  Missing prerequisite: branch or admin user not found.');
            $this->command->error('  Run BranchSeeder and SuperAdminSeeder first.');

            return;
        }

        $startDate = Carbon::parse('2025-12-07');
        $endDate = Carbon::parse('2026-06-02');
        $totalDays = $startDate->diffInDays($endDate);

        $created = 0;
        $skipped = 0;

        foreach (self::PATTERNS as $categoryName => $pattern) {
            $category = FinanceCategory::where('name', $categoryName)
                ->where('type', 'income')
                ->first();

            if (! $category) {
                $this->command->warn("  Category not found, skipped: {$categoryName}");

                continue;
            }

            for ($i = 1; $i <= $pattern['count']; $i++) {
                $reference = sprintf('DEV-EXTRA-%s-%02d', $category->id, $i);

                if (Transaction::where('reference', $reference)->exists()) {
                    $skipped++;

                    continue;
                }

                $amount = rand($pattern['range'][0] * 100, $pattern['range'][1] * 100) / 100;
                $daysOffset = rand(0, $totalDays);
                $transactionDate = $startDate->copy()->addDays($daysOffset);

                Transaction::create([
                    'branch_id' => $branch->id,
                    'category_id' => $category->id,
                    'type' => 'income',
                    'amount' => $amount,
                    'currency' => 'GHS',
                    'transaction_date' => $transactionDate,
                    'reference' => $reference,
                    'notes' => $pattern['note'],
                    'recorded_by' => $admin->id,
                ]);
                $created++;
            }
        }

        // Audit log so the dev-data origin is recorded
        activity()
            ->causedBy($admin)
            ->withProperties([
                'created' => $created,
                'skipped' => $skipped,
                'reason' => 'DEV seed - populate income categories for Reports module demo',
            ])
            ->log("DevExtraIncomeSeeder: created {$created} transactions, skipped {$skipped} existing");

        $this->command->info("  Created {$created} dev income transactions across 8 categories.");
        if ($skipped > 0) {
            $this->command->line("  Skipped {$skipped} existing (idempotent re-run).");
        }
    }
}
