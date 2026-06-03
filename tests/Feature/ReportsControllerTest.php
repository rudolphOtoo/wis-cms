<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();

        // A default recorder user so createIncome() always has a valid
        // recorded_by FK. Tests that need an authenticated requester use
        // financeToken() / memberToken() which create separate users.
        $this->recorder = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Recorder',
            'email' => 'recorder@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
    }

    /**
     * Create a finance_officer user (has 'view finance' permission)
     * and return a sanctum token for API authentication.
     */
    protected function financeToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('finance_officer');

        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Create a plain member user (no finance permissions)
     * for authorization tests.
     */
    protected function memberToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Member',
            'email' => 'member@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('member');

        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Convenience: create an income transaction with given amount + date.
     */
    protected function createIncome(FinanceCategory $cat, float $amount, string $date): Transaction
    {
        return Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $cat->id,
            'type' => 'income',
            'amount' => $amount,
            'transaction_date' => $date,
            'recorded_by' => $this->recorder->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // ACCESS CONTROL
    // ─────────────────────────────────────────────────────────────────

    public function test_finance_officer_can_access_income_report(): void
    {
        $token = $this->financeToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'period' => ['from', 'to'],
                'rows',
                'summary' => [
                    'grand_total',
                    'monthly_average',
                    'month_count',
                    'top_category',
                    'category_totals',
                ],
            ]);
    }

    public function test_member_without_view_finance_is_blocked(): void
    {
        $token = $this->memberToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_blocked(): void
    {
        $response = $this->getJson('/api/reports/finance/income-by-category');

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // AGGREGATION CORRECTNESS
    // ─────────────────────────────────────────────────────────────────

    public function test_returns_correct_grand_total_for_known_data(): void
    {
        $tithe = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);
        $offertory = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Offertory']);

        $this->createIncome($tithe, 1000, now()->subMonths(1)->toDateString());
        $this->createIncome($tithe, 500, now()->toDateString());
        $this->createIncome($offertory, 200, now()->toDateString());

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        $response->assertStatus(200);
        $this->assertEquals(1700, $response->json('summary.grand_total'));
        $this->assertEquals('Tithe', $response->json('summary.top_category'));
    }

    public function test_groups_rows_by_month_and_category(): void
    {
        $cat = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);

        // 2 rows same month + category → should collapse to 1 row with sum
        $this->createIncome($cat, 100, '2026-04-15');
        $this->createIncome($cat, 200, '2026-04-20');
        // 1 row different month → separate row
        $this->createIncome($cat, 300, '2026-05-10');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category?from_date=2026-04-01&to_date=2026-05-31');

        $rows = $response->json('rows');
        $this->assertCount(2, $rows);
        $this->assertEquals(300, collect($rows)->firstWhere('month', '2026-04')['total']);
        $this->assertEquals(300, collect($rows)->firstWhere('month', '2026-05')['total']);
    }

    public function test_respects_from_date_filter(): void
    {
        $cat = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);

        $this->createIncome($cat, 100, '2026-01-15');
        $this->createIncome($cat, 200, '2026-06-15');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category?from_date=2026-06-01&to_date=2026-06-30');

        $this->assertEquals(200, $response->json('summary.grand_total'));
    }

    // ─────────────────────────────────────────────────────────────────
    // EXCLUSION RULES
    // ─────────────────────────────────────────────────────────────────

    public function test_only_includes_income_type_not_expense(): void
    {
        $income = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);
        $expense = FinanceCategory::factory()->create(['type' => 'expense', 'name' => 'Utilities']);

        $this->createIncome($income, 500, now()->toDateString());

        // An expense transaction with the SAME amount - should NOT appear
        Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $expense->id,
            'type' => 'expense',
            'amount' => 500,
            'transaction_date' => now()->toDateString(),
            'recorded_by' => $this->recorder->id,
        ]);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        $this->assertEquals(500, $response->json('summary.grand_total'));
    }

    public function test_excludes_soft_deleted_transactions(): void
    {
        $cat = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);

        $live = $this->createIncome($cat, 500, now()->toDateString());
        $trashed = $this->createIncome($cat, 9999, now()->toDateString());
        $trashed->delete();  // soft-delete

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        // grand_total should equal only the live row, not include the 9999
        $this->assertEquals(500, $response->json('summary.grand_total'));
    }

    // ─────────────────────────────────────────────────────────────────
    // VALIDATION
    // ─────────────────────────────────────────────────────────────────

    public function test_validates_invalid_date_format(): void
    {
        $token = $this->financeToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category?from_date=not-a-date');

        $response->assertStatus(422);
    }

    public function test_validates_from_after_to(): void
    {
        $token = $this->financeToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category?from_date=2026-06-30&to_date=2026-06-01');

        $response->assertStatus(422);
    }
}
