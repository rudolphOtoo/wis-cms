<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Events\PaymentReceived;
use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('payments')]
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
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('finance_officer');

        return $user->createToken('test')->plainTextToken;
    }

    protected function superAdminToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Super Admin',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        return $user->createToken('test')->plainTextToken;
    }

    public function test_finance_stats_aggregate_income_and_expenses(): void
    {
        $token = $this->financeToken();
        $income = FinanceCategory::factory()->create(['type' => 'income']);
        $expense = FinanceCategory::factory()->create(['type' => 'expense']);
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
        $token = $this->financeToken();
        $category = FinanceCategory::factory()->create(['type' => 'income']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/finance/transactions', [
                'category_id' => $category->id,
                'type' => 'income',
                'amount' => 150.50,
                'transaction_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('transactions', ['amount' => 150.50]);
    }

    // ── Receipt number generation ───────────────────────────────────

    public function test_manual_cash_entry_generates_receipt_number(): void
    {
        $token = $this->financeToken();
        $category = FinanceCategory::factory()->create(['type' => 'income']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/finance/transactions', [
                'category_id' => $category->id,
                'type' => 'income',
                'amount' => 250.00,
                'transaction_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201);

        $receiptNumber = $response->json('data.receipt_number');

        $this->assertNotNull($receiptNumber);
        $this->assertMatchesRegularExpression('/^REC-\d{4}-\d{6}$/', $receiptNumber);
    }

    public function test_manual_cash_entry_receipt_numbers_increment_sequentially(): void
    {
        $token = $this->financeToken();
        $category = FinanceCategory::factory()->create(['type' => 'income']);

        $receipts = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/finance/transactions', [
                    'category_id' => $category->id,
                    'type' => 'income',
                    'amount' => 100 + $i,
                    'transaction_date' => now()->toDateString(),
                ]);

            $receipts[] = $response->json('data.receipt_number');
        }

        // First should end with 000001, second with 000002, third with 000003
        $this->assertStringEndsWith('000001', $receipts[0]);
        $this->assertStringEndsWith('000002', $receipts[1]);
        $this->assertStringEndsWith('000003', $receipts[2]);
    }

    public function test_manual_cash_entry_dispatches_payment_received_event(): void
    {
        Event::fake([PaymentReceived::class]);

        $token = $this->financeToken();
        $category = FinanceCategory::factory()->create(['type' => 'income']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/finance/transactions', [
                'category_id' => $category->id,
                'type' => 'income',
                'amount' => 100.00,
                'transaction_date' => now()->toDateString(),
            ]);

        Event::assertDispatched(PaymentReceived::class, function ($event) {
            return $event->model instanceof Transaction
                && $event->model->type === 'income';
        });
    }

    // ── Immutability guard (FIX-04) ─────────────────────────────────

    public function test_payment_linked_transaction_cannot_be_edited_by_finance_officer(): void
    {
        $token = $this->financeToken();
        $category = FinanceCategory::factory()->create(['type' => 'income']);

        // Create a transaction linked to a successful payment.
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'WIS-IMMUTABLE-TEST',
            'status' => PaymentStatus::Success,
            'amount' => 50.00,
            'payment_type' => 'tithe',
        ]);

        $transaction = Transaction::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'type' => 'income',
            'amount' => 50.00,
            'currency' => 'GHS',
            'transaction_date' => now()->toDateString(),
            'reference' => 'WIS-IMMUTABLE-TEST',
            'recorded_by' => User::first()->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/finance/transactions/{$transaction->id}", [
                'amount' => 999.00,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'This transaction was generated from a successful payment and cannot be edited.');
    }

    public function test_payment_linked_transaction_cannot_be_deleted_by_finance_officer(): void
    {
        $token = $this->financeToken();
        $category = FinanceCategory::factory()->create(['type' => 'income']);

        Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'WIS-DEL-TEST',
            'status' => PaymentStatus::Success,
            'amount' => 50.00,
            'payment_type' => 'tithe',
        ]);

        $transaction = Transaction::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'type' => 'income',
            'amount' => 50.00,
            'currency' => 'GHS',
            'transaction_date' => now()->toDateString(),
            'reference' => 'WIS-DEL-TEST',
            'recorded_by' => User::first()->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/finance/transactions/{$transaction->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'This transaction was generated from a successful payment and cannot be deleted.');

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_super_admin_can_edit_payment_linked_transaction(): void
    {
        $token = $this->superAdminToken();
        $category = FinanceCategory::factory()->create(['type' => 'income']);

        Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'WIS-SA-EDIT',
            'status' => PaymentStatus::Success,
            'amount' => 50.00,
            'payment_type' => 'tithe',
        ]);

        $transaction = Transaction::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'type' => 'income',
            'amount' => 50.00,
            'currency' => 'GHS',
            'transaction_date' => now()->toDateString(),
            'reference' => 'WIS-SA-EDIT',
            'recorded_by' => User::first()->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/finance/transactions/{$transaction->id}", [
                'amount' => 60.00,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'amount' => 60.00]);
    }
}
