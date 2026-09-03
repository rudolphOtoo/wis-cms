<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Cold-boot Paystack reconciliation.
 *
 * Simulates the church desktop PC being off while members pay via Paystack
 * payment links, then booting up and running `payments:reconcile-paystack`
 * to back-fill payments + finance ledger entries from the cloud transaction
 * history.
 */
#[Group('payments')]
class PaystackReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret' => 'sk_test_reconcile',
            'services.paystack.base_url' => 'https://api.paystack.co',
            'services.paystack.reconcile_overlap_minutes' => 60,
            'services.mnotify.api_key' => 'mnotify-test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
            'services.mnotify.dry_run' => false,
        ]);

        $this->branch = Branch::factory()->create();

        // Ledger categories required by Payment::createTransactionFromPayment().
        foreach (['tithe' => 'Tithe', 'offering' => 'Offertory', 'building_fund' => 'Scholarship Fund'] as $type => $name) {
            FinanceCategory::firstOrCreate(
                ['name' => $name, 'type' => 'income'],
                ['is_active' => true, 'payment_type' => $type]
            );
        }
    }

    private function paystackTransaction(array $overrides = []): array
    {
        return array_merge([
            'id' => 987654,
            'reference' => 'PSK_OFFLINE_TITHE_1',
            'amount' => 5000, // pesewas → GHS 50.00
            'currency' => 'GHS',
            'channel' => 'mobile_money',
            'status' => 'success',
            'paid_at' => '2026-08-24T10:30:00.000Z',
            'created_at' => '2026-08-24T10:30:00.000Z',
            'gateway_response' => 'Approved',
            'metadata' => [
                'branch_id' => $this->branch->id,
                'payment_type' => 'tithe',
                'phone' => '0241234567',
            ],
            'customer' => [
                'email' => 'giver@example.com',
                'phone' => '0241234567',
            ],
            'authorization' => [
                'channel' => 'mobile_money',
                'mobile_money' => ['provider' => 'mtn', 'phone' => '0241234567'],
            ],
        ], $overrides);
    }

    private function fakePaystack(array $transactions, bool $paginated = false): void
    {
        Http::fake(function ($request) use ($transactions, $paginated) {
            if (! str_contains($request->url(), 'api.paystack.co/transaction')) {
                return Http::response(['status' => false], 404);
            }

            if (! $paginated) {
                return Http::response([
                    'status' => true,
                    'data' => $transactions,
                    'meta' => ['total' => count($transactions), 'page' => 1, 'pageCount' => 1],
                ], 200);
            }

            // Paginate: page 1 carries the first N, page 2 the rest.
            $page = (int) ($request['page'] ?? 1);
            $perPage = 100;
            $chunk = array_slice($transactions, ($page - 1) * $perPage, $perPage);
            $total = count($transactions);
            $pageCount = (int) ceil($total / $perPage);

            return Http::response([
                'status' => true,
                'data' => $chunk,
                'meta' => ['total' => $total, 'page' => $page, 'pageCount' => $pageCount],
            ], 200);
        });
    }

    // ─── Core scenario ──────────────────────────────────────────────

    public function test_reconcile_fetches_remote_transactions_and_populates_local_database(): void
    {
        $this->fakePaystack([
            $this->paystackTransaction(),
            $this->paystackTransaction([
                'reference' => 'PSK_OFFLINE_BUILDING_2',
                'amount' => 20000,
                'metadata' => [
                    'branch_id' => $this->branch->id,
                    'payment_type' => 'building_fund',
                    'phone' => '0209876543',
                ],
            ]),
        ]);

        $this->artisan('payments:reconcile-paystack', ['--no-sms' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('transactions', 2);

        $tithe = Payment::withoutGlobalScopes()->where('reference', 'PSK_OFFLINE_TITHE_1')->first();
        $this->assertSame('success', $tithe->status->value);
        $this->assertSame('synced_from_remote', $tithe->sync_status);
        $this->assertSame('50.00', $tithe->amount);
        $this->assertSame('tithe', $tithe->payment_type->value);
        $this->assertSame('0241234567', $tithe->momo_number);
        $this->assertTrue($tithe->sms_pending);
        $this->assertSame($this->branch->id, $tithe->branch_id);

        $building = Payment::withoutGlobalScopes()->where('reference', 'PSK_OFFLINE_BUILDING_2')->first();
        $this->assertSame('building_fund', $building->payment_type->value);
        $this->assertSame('200.00', $building->amount);

        $this->assertSame(
            FinanceCategory::where('payment_type', 'tithe')->value('id'),
            Transaction::where('reference', 'PSK_OFFLINE_TITHE_1')->value('category_id'),
        );
    }

    // ─── Strict idempotency / zero duplicate crediting ──────────────

    public function test_running_reconciliation_twice_yields_exactly_one_ledger_entry_per_payment(): void
    {
        $this->fakePaystack([
            $this->paystackTransaction(),
            $this->paystackTransaction([
                'reference' => 'PSK_OFFLINE_BUILDING_2',
                'amount' => 20000,
                'metadata' => ['branch_id' => $this->branch->id, 'payment_type' => 'building_fund', 'phone' => '0209876543'],
            ]),
        ]);

        foreach ([1, 2, 3] as $run) {
            $this->artisan('payments:reconcile-paystack', ['--no-sms' => true])
                ->assertSuccessful();
        }

        // 2 payments, not 6; 2 ledger entries, not 6.
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(
            1,
            Transaction::where('reference', 'PSK_OFFLINE_TITHE_1')->where('type', 'income')->count(),
        );
    }

    public function test_reconcile_is_idempotent_when_payment_was_already_confirmed_locally(): void
    {
        $payment = Payment::factory()->successful()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'PSK_ALREADY_LOCAL',
            'payment_type' => 'tithe',
            'amount' => 50.00,
            'momo_number' => '0241234567',
        ]);
        $payment->createTransactionFromPayment();

        $this->fakePaystack([$this->paystackTransaction(['reference' => 'PSK_ALREADY_LOCAL'])]);

        $this->artisan('payments:reconcile-paystack', ['--no-sms' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_reconcile_upgrades_pending_app_initiated_payment_without_duplicating_ledger(): void
    {
        // App-initiated payment (WIS reference) left pending while the PC was off.
        $payment = Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'reference' => 'WIS-20260824-AB12CD34',
            'payment_type' => 'offering',
            'amount' => 100.00,
            'status' => 'pending',
            'momo_number' => '0245550000',
            'momo_network' => 'mtn',
            'metadata' => [
                'payment_type' => 'offering',
                'branch_id' => $this->branch->id,
                'payment_id' => null,
            ],
        ]);

        $this->fakePaystack([$this->paystackTransaction([
            'reference' => 'WIS-20260824-AB12CD34',
            'amount' => 10000,
            'metadata' => ['branch_id' => $this->branch->id, 'payment_type' => 'offering'],
        ])]);

        // Run twice to prove ledger stays at exactly one row.
        $this->artisan('payments:reconcile-paystack', ['--no-sms' => true])->assertSuccessful();
        $this->artisan('payments:reconcile-paystack', ['--no-sms' => true])->assertSuccessful();

        $fresh = $payment->fresh();
        $this->assertSame('success', $fresh->status->value);
        $this->assertSame('synced_from_remote', $fresh->sync_status);
        // Locally captured phone preserved through the remote poll.
        $this->assertSame('0245550000', $fresh->momo_number);
        $this->assertTrue($fresh->sms_pending);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(
            '100.00',
            Transaction::where('reference', 'WIS-20260824-AB12CD34')->value('amount'),
        );
    }

    // ─── Off-grid SMS receipt fallback (Phase 3 Option B) ───────────

    public function test_reconcile_sends_pending_receipt_sms_on_boot(): void
    {
        $transaction = $this->paystackTransaction();

        Http::fake([
            'api.paystack.co/transaction*' => Http::response([
                'status' => true,
                'data' => [$transaction],
                'meta' => ['total' => 1, 'page' => 1, 'pageCount' => 1],
            ], 200),
            'api.mnotify.com/api/sms/quick*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->artisan('payments:reconcile-paystack')
            ->assertSuccessful();

        $payment = Payment::withoutGlobalScopes()->where('reference', 'PSK_OFFLINE_TITHE_1')->first();
        $this->assertFalse($payment->sms_pending);
        $this->assertNotNull($payment->receipt_sms_sent_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.mnotify.com/api/sms/quick'));
    }

    public function test_reconcile_leaves_sms_pending_when_mnotify_rejects(): void
    {
        $transaction = $this->paystackTransaction();

        Http::fake([
            'api.paystack.co/transaction*' => Http::response([
                'status' => true,
                'data' => [$transaction],
                'meta' => ['total' => 1, 'page' => 1, 'pageCount' => 1],
            ], 200),
            'api.mnotify.com/api/sms/quick*' => Http::response(['status' => 'failed'], 400),
        ]);

        $this->artisan('payments:reconcile-paystack')
            ->assertSuccessful();

        $payment = Payment::withoutGlobalScopes()->where('reference', 'PSK_OFFLINE_TITHE_1')->first();
        $this->assertTrue($payment->sms_pending);
        $this->assertNull($payment->receipt_sms_sent_at);
    }

    // ─── Pagination / resilience ────────────────────────────────────

    public function test_reconcile_paginates_through_paystack(): void
    {
        $transactions = [];
        for ($i = 0; $i < 101; $i++) {
            $transactions[] = $this->paystackTransaction([
                'reference' => "PSK_PAGE_{$i}",
                'amount' => 1000 + $i,
                'metadata' => ['branch_id' => $this->branch->id, 'payment_type' => 'offering', 'phone' => '0241234567'],
            ]);
        }

        $this->fakePaystack($transactions, true);

        $this->artisan('payments:reconcile-paystack', ['--no-sms' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('payments', 101);
        $this->assertDatabaseCount('transactions', 101);

        Http::assertSentCount(2);
    }

    public function test_command_fails_gracefully_when_paystack_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->artisan('payments:reconcile-paystack', ['--no-sms' => true])
            ->assertExitCode(1);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_command_fails_gracefully_when_secret_key_missing(): void
    {
        config(['services.paystack.secret' => '']);

        $this->artisan('payments:reconcile-paystack', ['--no-sms' => true])
            ->assertExitCode(1);
    }
}
