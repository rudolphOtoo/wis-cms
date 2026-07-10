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

class FinanceExportTest extends TestCase
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

    protected function makeTransaction(array $attrs = []): Transaction
    {
        $cat = FinanceCategory::firstOrCreate(
            ['name' => 'Tithes'],
            ['type' => 'income', 'is_active' => true]
        );

        return Transaction::create(array_merge([
            'branch_id' => $this->branch->id,
            'category_id' => $cat->id,
            'type' => 'income',
            'amount' => 250.00,
            'currency' => 'GHS',
            'transaction_date' => now()->toDateString(),
            'reference' => 'REF-001',
        ], $attrs));
    }

    /**
     * Extract all text content from an XLSX binary string by reading
     * the worksheet and shared strings XML inside the ZIP archive.
     */
    protected function xlsxText(string $xlsx): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        file_put_contents($tmp, $xlsx);

        $zip = new \ZipArchive;
        $zip->open($tmp);

        // Try shared strings first (used when SHOULD_USE_INLINE_STRINGS = false)
        $text = $zip->getFromName('xl/sharedStrings.xml') ?? '';

        // Also read the worksheet itself (inline strings live here)
        $text .= $zip->getFromName('xl/worksheets/sheet1.xml') ?? '';

        $zip->close();
        unlink($tmp);

        // Strip XML tags to get raw text content
        return strip_tags($text);
    }

    public function test_finance_officer_can_export_transactions(): void
    {
        $this->makeTransaction(['reference' => 'TITHE-AAA']);
        $officer = $this->userWithRole('finance_officer');

        $response = $this->withHeader('Authorization', "Bearer {$this->token($officer)}")
            ->get('/api/finance/transactions/export');

        $response->assertOk();
        $content = $this->xlsxText($response->streamedContent());
        $this->assertStringContainsString('Date', $content);
        $this->assertStringContainsString('TITHE-AAA', $content);
        $this->assertStringContainsString('Tithes', $content);
    }

    public function test_export_respects_type_filter(): void
    {
        $this->makeTransaction(['type' => 'income', 'reference' => 'INCOME-ROW']);
        $expenseCat = FinanceCategory::firstOrCreate(['name' => 'Utilities'], ['type' => 'expense', 'is_active' => true]);
        $this->makeTransaction(['type' => 'expense', 'category_id' => $expenseCat->id, 'reference' => 'EXPENSE-ROW']);

        $officer = $this->userWithRole('finance_officer');

        $content = $this->xlsxText(
            $this->withHeader('Authorization', "Bearer {$this->token($officer)}")
                ->get('/api/finance/transactions/export?type=income')
                ->streamedContent(),
        );

        $this->assertStringContainsString('INCOME-ROW', $content);
        $this->assertStringNotContainsString('EXPENSE-ROW', $content);
    }

    public function test_usher_cannot_export_finance(): void
    {
        $usher = $this->userWithRole('usher');

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->get('/api/finance/transactions/export')
            ->assertStatus(403);
    }

    public function test_department_leader_cannot_export_finance(): void
    {
        $leader = $this->userWithRole('department_leader');

        $this->withHeader('Authorization', "Bearer {$this->token($leader)}")
            ->get('/api/finance/transactions/export')
            ->assertStatus(403);
    }
}
