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

class FinancialLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    protected function userWithRole(string $role): User
    {
        $user = User::create([
            'branch_id' => $this->branch->id, 'name' => ucfirst($role),
            'email' => "{$role}@wis-cms.local", 'password' => Hash::make('Password@123'), 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function token(User $u): string
    {
        return $u->createToken('test')->plainTextToken;
    }

    public function test_finance_officer_can_download_ledger_pdf(): void
    {
        $cat = FinanceCategory::firstOrCreate(['name' => 'Tithes'], ['type' => 'income', 'is_active' => true]);
        Transaction::create([
            'branch_id' => $this->branch->id, 'category_id' => $cat->id, 'type' => 'income',
            'amount' => 500, 'currency' => 'GHS', 'transaction_date' => '2026-05-10', 'reference' => 'T1',
        ]);

        $officer = $this->userWithRole('finance_officer');

        $response = $this->withHeader('Authorization', "Bearer {$this->token($officer)}")
            ->get('/api/finance/reports/ledger?from=2026-05-01&to=2026-05-31');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('financial-ledger-2026-05-01-to-2026-05-31', $response->headers->get('content-disposition'));
    }

    public function test_ledger_works_with_no_transactions_in_period(): void
    {
        // Empty period — should still return a valid PDF ("no activity"),
        // not 404 or error.
        $officer = $this->userWithRole('finance_officer');

        $response = $this->withHeader('Authorization', "Bearer {$this->token($officer)}")
            ->get('/api/finance/reports/ledger?from=2026-05-01&to=2026-05-31');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_ledger_validates_date_range(): void
    {
        $officer = $this->userWithRole('finance_officer');

        // 'to' before 'from' should fail validation
        $this->withHeader('Authorization', "Bearer {$this->token($officer)}")
            ->getJson('/api/finance/reports/ledger?from=2026-05-31&to=2026-05-01')
            ->assertStatus(422);

        // Missing dates fail too
        $this->withHeader('Authorization', "Bearer {$this->token($officer)}")
            ->getJson('/api/finance/reports/ledger')
            ->assertStatus(422);
    }

    public function test_usher_cannot_download_ledger(): void
    {
        $usher = $this->userWithRole('usher');

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->get('/api/finance/reports/ledger?from=2026-05-01&to=2026-05-31')
            ->assertStatus(403);
    }

    public function test_department_leader_cannot_download_ledger(): void
    {
        $leader = $this->userWithRole('department_leader');

        $this->withHeader('Authorization', "Bearer {$this->token($leader)}")
            ->get('/api/finance/reports/ledger?from=2026-05-01&to=2026-05-31')
            ->assertStatus(403);
    }
}
