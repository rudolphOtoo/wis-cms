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

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    protected function financeToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name'      => 'Finance',
            'email'     => 'finance@test.local',
            'password'  => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('finance_officer');
        return $user->createToken('test')->plainTextToken;
    }

    public function test_finance_stats_aggregate_income_and_expenses(): void
    {
        $token    = $this->financeToken();
        $income   = FinanceCategory::factory()->create(['type' => 'income']);
        $expense  = FinanceCategory::factory()->create(['type' => 'expense']);
        $recorder = User::first();

        // Two income transactions this month
        Transaction::factory()->create([
            'branch_id' => $this->branch->id, 'category_id' => $income->id,
            'type' => 'income', 'amount' => 500, 'transaction_date' => now(),
            'recorded_by' => $recorder->id,
        ]);
        Transaction::factory()->create([
            'branch_id' => $this->branch->id, 'category_id' => $income->id,
            'type' => 'income', 'amount' => 300, 'transaction_date' => now(),
            'recorded_by' => $recorder->id,
        ]);
        // One expense this month
        Transaction::factory()->create([
            'branch_id' => $this->branch->id, 'category_id' => $expense->id,
            'type' => 'expense', 'amount' => 200, 'transaction_date' => now(),
            'recorded_by' => $recorder->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->getJson('/api/finance/stats');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(800, $data['this_month_income']);
        $this->assertEquals(200, $data['this_month_expenses']);
        $this->assertEquals(600, $data['this_month_balance']);
    }

    public function test_finance_officer_can_create_transaction(): void
    {
        $token    = $this->financeToken();
        $category = FinanceCategory::factory()->create(['type' => 'income']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/finance/transactions', [
                'category_id'      => $category->id,
                'type'             => 'income',
                'amount'           => 150.50,
                'transaction_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('transactions', ['amount' => 150.50]);
    }
}
