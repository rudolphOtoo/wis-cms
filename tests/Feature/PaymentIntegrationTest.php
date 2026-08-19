<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $financeOfficer;

    protected User $secretary;

    protected User $pastor;

    protected User $member;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->financeOfficer = User::create([
            'name' => 'Finance Officer',
            'email' => 'finance@test.com',
            'password' => Hash::make('password'),
            'password_changed_at' => now(),
            'branch_id' => $this->branch->id,
        ]);
        $this->financeOfficer->assignRole('finance_officer');

        $this->secretary = User::create([
            'name' => 'Secretary',
            'email' => 'secretary@test.com',
            'password' => Hash::make('password'),
            'password_changed_at' => now(),
            'branch_id' => $this->branch->id,
        ]);
        $this->secretary->assignRole('secretary');

        $this->pastor = User::create([
            'name' => 'Pastor',
            'email' => 'pastor@test.com',
            'password' => Hash::make('password'),
            'password_changed_at' => now(),
            'branch_id' => $this->branch->id,
        ]);
        $this->pastor->assignRole('pastor');

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => Hash::make('password'),
            'password_changed_at' => now(),
            'branch_id' => $this->branch->id,
        ]);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'password_changed_at' => now(),
            'branch_id' => $this->branch->id,
        ]);
        $this->superAdmin->assignRole('super_admin');

        // Seed FinanceCategories with payment_type mapping (required by createTransactionFromPayment)
        $categoryMap = [
            'tithe' => 'Tithe',
            'offering' => 'Offertory',
            'welfare' => 'Welfare',
            'building_fund' => 'Scholarship Fund',
            'special_seed' => 'Thanksgiving',
            'other' => 'Others',
        ];
        foreach ($categoryMap as $paymentType => $categoryName) {
            FinanceCategory::firstOrCreate(
                ['name' => $categoryName, 'type' => 'income'],
                ['is_active' => true, 'payment_type' => $paymentType]
            );
        }
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function paymentPayload(array $overrides = []): array
    {
        return array_merge([
            'payment_type' => 'tithe',
            'amount' => 50.00,
            'channel' => 'momo',
            'momo_network' => 'mtn',
            'momo_number' => '0241234567',
            'notes' => 'Sunday tithe',
        ], $overrides);
    }

    // ── Initialize payment ──────────────────────────────────────────

    public function test_finance_officer_can_initialize_payment(): void
    {
        $payload = $this->paymentPayload();

        // Mock Paystack API response
        Http::fake([
            'api.paystack.co/charge' => Http::response([
                'status' => true,
                'message' => 'Charge attempted',
                'data' => [
                    'reference' => 'MOMO_XYZ123',
                    'status' => 'pending',
                    'display_text' => 'Please approve the payment on your phone',
                    'amount' => 5000,
                    'currency' => 'GHS',
                    'channel' => 'mobile_money',
                    'provider' => 'MTN',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/payments/initialize', $payload, [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'reference',
                    'display_text',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('payments', [
            'reference' => 'MOMO_XYZ123',
            'payment_type' => 'tithe',
            'status' => 'pending',
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
        ]);
    }

    public function test_member_without_manage_payments_can_still_initialize_payment(): void
    {
        // Regular member has no finance permissions, but anyone can pay
        $payload = $this->paymentPayload();

        Http::fake([
            'api.paystack.co/charge' => Http::response([
                'status' => true,
                'message' => 'Charge attempted',
                'data' => [
                    'reference' => 'MOMO_ANON01',
                    'status' => 'pending',
                    'display_text' => 'Approve on phone',
                    'amount' => 5000,
                    'currency' => 'GHS',
                    'channel' => 'mobile_money',
                    'provider' => 'MTN',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/payments/initialize', $payload, [
            'Authorization' => 'Bearer '.$this->token($this->member),
        ]);

        $response->assertCreated();
    }

    public function test_initialize_payment_rejects_invalid_payment_type(): void
    {
        $payload = $this->paymentPayload(['payment_type' => 'invalid_type']);

        $response = $this->postJson('/api/payments/initialize', $payload, [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_type']);
    }

    public function test_initialize_payment_rejects_missing_momo_network(): void
    {
        $payload = $this->paymentPayload();
        unset($payload['momo_network']);

        $response = $this->postJson('/api/payments/initialize', $payload, [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['momo_network']);
    }

    public function test_initialize_payment_rejects_negative_amount(): void
    {
        $payload = $this->paymentPayload(['amount' => -10]);

        $response = $this->postJson('/api/payments/initialize', $payload, [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_unauthenticated_user_cannot_initialize_payment(): void
    {
        $response = $this->postJson('/api/payments/initialize', $this->paymentPayload());

        $response->assertUnauthorized();
    }

    // ── Verify payment ──────────────────────────────────────────────

    public function test_user_can_verify_pending_payment(): void
    {
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'MOMO_VERIFY1',
            'status' => 'pending',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'reference' => 'MOMO_VERIFY1',
                    'status' => 'success',
                    'amount' => 5000,
                    'gateway_response' => 'Approved',
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/payments/verify/MOMO_VERIFY1', [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertDatabaseHas('payments', [
            'reference' => 'MOMO_VERIFY1',
            'status' => 'success',
        ]);
    }

    public function test_verify_creates_transaction_record_on_success(): void
    {
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'MOMO_TXN_CREATE',
            'status' => 'pending',
            'amount' => 100.00,
            'payment_type' => 'tithe',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'reference' => 'MOMO_TXN_CREATE',
                    'status' => 'success',
                    'amount' => 10000,
                ],
            ], 200),
        ]);

        $this->getJson('/api/payments/verify/MOMO_TXN_CREATE', [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $this->assertDatabaseHas('transactions', [
            'branch_id' => $this->branch->id,
            'amount' => 100.00,
            'type' => 'income',
            'category_id' => FinanceCategory::where('payment_type', 'tithe')->value('id'),
        ]);
    }

    // ── Webhook ─────────────────────────────────────────────────────

    public function test_webhook_processes_charge_success(): void
    {
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'MOMO_WEBHOOK1',
            'status' => 'pending',
        ]);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'MOMO_WEBHOOK1',
                'status' => 'success',
                'amount' => 5000,
                'gateway_response' => 'Approved',
            ],
        ]);

        $secret = config('services.paystack.webhook_secret', 'test_secret');
        $signature = hash_hmac('sha512', $payload, $secret);

        $response = $this->postJson('/api/webhooks/payments/paystack', json_decode($payload, true), [
            'X-Paystack-Signature' => $signature,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Webhook received');

        $this->assertDatabaseHas('payments', [
            'reference' => 'MOMO_WEBHOOK1',
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('transactions', [
            'amount' => 50.00,
            'type' => 'income',
            'category_id' => FinanceCategory::where('payment_type', 'tithe')->value('id'),
        ]);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'MOMO_BAD_SIG', 'status' => 'success'],
        ]);

        $response = $this->postJson('/api/webhooks/payments/paystack', json_decode($payload, true), [
            'X-Paystack-Signature' => 'invalid_signature_value',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Invalid signature');
    }

    public function test_webhook_is_idempotent(): void
    {
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'MOMO_IDEMPOTENT',
            'status' => 'success',
        ]);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'MOMO_IDEMPOTENT', 'status' => 'success'],
        ]);

        $secret = config('services.paystack.webhook_secret', 'test_secret');
        $signature = hash_hmac('sha512', $payload, $secret);

        $response = $this->postJson('/api/webhooks/payments/paystack', json_decode($payload, true), [
            'X-Paystack-Signature' => $signature,
        ]);

        $response->assertOk();
    }

    public function test_webhook_does_not_duplicate_transactions(): void
    {
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'MOMO_NO_DUP',
            'status' => 'success',
        ]);

        $txnCountBefore = Transaction::where('branch_id', $this->branch->id)->count();

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'MOMO_NO_DUP', 'status' => 'success'],
        ]);

        $secret = config('services.paystack.webhook_secret', 'test_secret');
        $signature = hash_hmac('sha512', $payload, $secret);

        $this->postJson('/api/webhooks/payments/paystack', json_decode($payload, true), [
            'X-Paystack-Signature' => $signature,
        ]);

        $txnCountAfter = Transaction::where('branch_id', $this->branch->id)->count();

        $this->assertEquals($txnCountBefore, $txnCountAfter);
    }

    // ── Permission gating ───────────────────────────────────────────

    public function test_finance_officer_can_view_payments(): void
    {
        Payment::factory()->count(3)->create(['branch_id' => $this->branch->id]);

        $response = $this->getJson('/api/payments/history?per_page=10', [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => [], 'meta' => []]);
    }

    public function test_secretary_can_view_payments(): void
    {
        Payment::factory()->count(2)->create(['branch_id' => $this->branch->id]);

        $response = $this->getJson('/api/payments/history?per_page=10', [
            'Authorization' => 'Bearer '.$this->token($this->secretary),
        ]);

        $response->assertOk();
    }

    public function test_member_cannot_view_payments(): void
    {
        $response = $this->getJson('/api/payments/history', [
            'Authorization' => 'Bearer '.$this->token($this->member),
        ]);

        $response->assertForbidden();
    }

    public function test_finance_officer_can_view_payment_stats(): void
    {
        $response = $this->getJson('/api/payments/stats', [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'this_month' => [
                        'total_received',
                        'success_count',
                        'pending_count',
                        'failed_count',
                        'total_count',
                    ],
                ],
            ]);
    }

    // ── Branch scoping ──────────────────────────────────────────────

    public function test_payments_are_scoped_to_branch(): void
    {
        $otherBranch = Branch::factory()->create();

        Payment::factory()->count(3)->create(['branch_id' => $this->branch->id]);
        Payment::factory()->count(2)->create(['branch_id' => $otherBranch->id]);

        $response = $this->getJson('/api/payments/history?per_page=100', [
            'Authorization' => 'Bearer '.$this->token($this->financeOfficer),
        ]);

        $response->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertDatabaseCount('payments', 5);
        $this->assertEquals(3, Payment::where('branch_id', $this->branch->id)->count());
        $this->assertEquals(2, Payment::where('branch_id', $otherBranch->id)->count());
    }
}
