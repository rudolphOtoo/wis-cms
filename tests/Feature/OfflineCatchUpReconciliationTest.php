<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ScheduledSmsDelivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Simulates the system going offline and coming back online.
 *
 * Scenario: The church PC was powered off for 3 days. During that time,
 * mNotify executed some scheduled SMS, rejected others (e.g. no credits),
 * and some are still pending. On boot, the reconciliation command should
 * correctly determine each message's fate and persist the raw provider
 * feedback for admin audit.
 */
class OfflineCatchUpReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);

        $this->branch = Branch::factory()->create();

        // Simulate "now" as if we just booted up after 3 days offline
        Carbon::setTestNow(Carbon::parse('2026-08-26 08:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Core Scenario ─────────────────────────────────────────

    /**
     * Full offline catch-up: 5 messages with different provider outcomes.
     *
     * Messages scheduled 3 days ago while the system was off:
     *   1. Delivered successfully — mNotify confirms via /reports/campaigns
     *   2. Rejected (insufficient credits) — mNotify reports failed
     *   3. Sent but purged from schedule — found in delivery reports only
     *   4. Still queued on mNotify (never executed) — in /scheduled
     *   5. Missing from everywhere, balance depleted → credit depletion
     */
    public function test_offline_catch_up_reconciles_all_message_fates(): void
    {
        // ── Pre-schedule 5 deliveries that were created before the outage ──

        // Message 1: Was scheduled_remote, mNotify reports "sent"
        $delivered = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Happy Wednesday service tonight at 7pm 🙏',
            'scheduled_at' => Carbon::parse('2026-08-23 07:00:00'), // 3 days ago
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'job-delivered-001',
            'source_type' => 'reminder',
        ]);

        // Message 2: Was cancelled (system expired it while offline), mNotify reports failed
        $rejected = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234568',
            'message_body' => 'Happy Birthday, God bless you! 🎂',
            'scheduled_at' => Carbon::parse('2026-08-23 07:00:00'),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
            'mnotify_job_id' => 'job-rejected-002',
            'source_type' => 'birthday',
        ]);

        // Message 3: Was cancelled, purged from schedule but in delivery reports as "delivered"
        $purged = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234569',
            'message_body' => 'Sunday service reminder for Aug 24 ⛪',
            'scheduled_at' => Carbon::parse('2026-08-24 06:30:00'),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
            'mnotify_job_id' => 'job-purged-003',
            'source_type' => 'reminder',
        ]);

        // Message 4: Still scheduled_remote, mNotify still has it as "scheduled"
        $stillPending = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234570',
            'message_body' => 'Prayer meeting Friday at 6pm',
            'scheduled_at' => Carbon::parse('2026-08-28 17:00:00'), // future
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'job-pending-004',
            'source_type' => 'reminder',
        ]);

        // Message 5: Was cancelled, missing from everywhere, zero balance
        $creditDepleted = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234571',
            'message_body' => 'Bible study Wednesday at 6pm',
            'scheduled_at' => Carbon::parse('2026-08-23 17:00:00'),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
            'source_type' => 'reminder',
        ]);

        // ── Mock mNotify's API responses ──

        $deliveryReportTimestamp = Carbon::parse('2026-08-23 07:01:30');

        Http::fake(function ($request) {
            $url = $request->url();

            // Balance endpoint: zero credits (explains why message 5 failed)
            if (str_contains($url, '/balance')) {
                return Http::response(['summary' => ['balance' => 0]], 200);
            }

            // Delivery reports: messages 1 and 3 were delivered
            if (str_contains($url, '/reports/campaigns')) {
                return Http::response([
                    'summary' => [
                        [
                            '_id' => 'job-delivered-001',
                            'message' => 'Happy Wednesday service tonight at 7pm 🙏',
                            'phone' => '0241234567',
                            'date_time' => '2026-08-23 07:01:30',
                            'status' => 'sent',
                            'sent_time' => '2026-08-23 07:01:30',
                        ],
                        [
                            '_id' => 'job-purged-003',
                            'message' => 'Sunday service reminder for Aug 24 ⛪',
                            'phone' => '0241234569',
                            'date_time' => '2026-08-24 06:30:15',
                            'status' => 'delivered',
                            'sent_time' => '2026-08-24 06:30:15',
                        ],
                    ],
                ], 200);
            }

            // Schedule: message 2 (failed, rejected by provider), message 4 (still scheduled)
            if (str_contains($url, '/scheduled')) {
                return Http::response([
                    'summary' => [
                        [
                            '_id' => 'job-rejected-002',
                            'message' => 'Happy Birthday, God bless you! 🎂',
                            'date_time' => '2026-08-23 07:00:00',
                            'status' => 'failed',
                            'message_detail' => 'Insufficient balance',
                        ],
                        [
                            '_id' => 'job-pending-004',
                            'message' => 'Prayer meeting Friday at 6pm',
                            'date_time' => '2026-08-28 17:00:00',
                            'status' => 'scheduled',
                        ],
                    ],
                ], 200);
            }

            return Http::response(['summary' => []], 200);
        });

        // ── Run reconciliation ──

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        // ── Assert each message's fate ──

        // Message 1: Confirmed dispatched, raw response persisted
        $fresh = $delivered->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $fresh->status);
        $this->assertNotNull($fresh->mnotify_response, 'Raw mNotify response should be persisted for auditors');
        $this->assertSame('job-delivered-001', $fresh->mnotify_response['_id'] ?? null);

        // Message 2: Rejected by provider → failed_provider
        $fresh = $rejected->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_FAILED_PROVIDER, $fresh->status);
        $this->assertNotNull($fresh->failure_reason);
        $this->assertStringContainsString('INSUFFICIENT_BALANCE', $fresh->failure_reason);

        // Message 3: Confirmed via delivery report, raw response persisted
        $fresh = $purged->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $fresh->status);
        $this->assertNotNull($fresh->mnotify_response, 'Delivery report should be persisted for auditors');
        $this->assertSame('delivered', $fresh->mnotify_response['status'] ?? null);

        // Message 4: Still scheduled → unchanged
        $fresh = $stillPending->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $fresh->status);

        // Message 5: Missing from everywhere + zero balance → credit depletion
        $fresh = $creditDepleted->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_FAILED_PROVIDER, $fresh->status);
        $this->assertStringContainsString('UNCONFIRMED_POSSIBLE_CREDIT_DEPLETION', $fresh->failure_reason);
    }

    // ─── Raw Response Persistence ──────────────────────────────

    /**
     * Confirms that raw mNotify API responses are stored on dispatched records
     * for admin audit trail and provider troubleshooting.
     */
    public function test_reconciliation_persists_raw_mnotify_response_on_dispatch(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Test message',
            'scheduled_at' => now()->subDay(),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'audit-001',
        ]);

        $rawReport = [
            '_id' => 'audit-001',
            'message' => 'Test message',
            'phone' => '0241234567',
            'date_time' => now()->subDay()->format('Y-m-d H:i:s'),
            'status' => 'sent',
            'sent_time' => now()->subDay()->addMinutes(2)->format('Y-m-d H:i:s'),
            'carrier' => 'MTN Ghana',
        ];

        Http::fake(function ($request) use ($rawReport) {
            $url = $request->url();
            if (str_contains($url, '/balance')) {
                return Http::response(['summary' => ['balance' => 100.0]], 200);
            }
            if (str_contains($url, '/reports/campaigns')) {
                return Http::response(['summary' => []], 200);
            }

            return Http::response([
                'summary' => [$rawReport],
            ], 200);
        });

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $fresh = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $fresh->status);
        $this->assertNotNull($fresh->mnotify_response);
        $this->assertSame('audit-001', $fresh->mnotify_response['_id']);
        $this->assertSame('sent', $fresh->mnotify_response['status']);
        $this->assertSame('MTN Ghana', $fresh->mnotify_response['carrier']);
    }

    /**
     * Confirms that raw mNotify API responses are stored on failed_provider records.
     */
    public function test_reconciliation_persists_raw_mnotify_response_on_failure(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Failed message',
            'scheduled_at' => now()->subDay(),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'fail-001',
        ]);

        $rawReport = [
            '_id' => 'fail-001',
            'message' => 'Failed message',
            'date_time' => now()->subDay()->format('Y-m-d H:i:s'),
            'status' => 'failed',
            'message_detail' => 'Insufficient balance',
        ];

        Http::fake(function ($request) use ($rawReport) {
            $url = $request->url();
            if (str_contains($url, '/balance')) {
                return Http::response(['summary' => ['balance' => 200.0]], 200);
            }
            if (str_contains($url, '/reports/campaigns')) {
                return Http::response(['summary' => []], 200);
            }

            return Http::response([
                'summary' => [$rawReport],
            ], 200);
        });

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $fresh = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_FAILED_PROVIDER, $fresh->status);
        $this->assertNotNull($fresh->mnotify_response);
        $this->assertSame('fail-001', $fresh->mnotify_response['_id']);
        $this->assertSame('failed', $fresh->mnotify_response['status']);
    }

    // ─── Delivery Report Fuzzy Matching ────────────────────────

    /**
     * Confirms that delivery reports are matched by phone + time window
     * even when the _id doesn't match (mNotify sometimes assigns new IDs
     * after re-attempting a failed send).
     */
    public function test_reconciliation_matches_delivery_report_by_phone_and_time(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Midweek service reminder',
            'scheduled_at' => Carbon::parse('2026-08-23 17:00:00'),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
            // No mnotify_job_id — the original job was rejected and re-sent
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/balance')) {
                return Http::response(['summary' => ['balance' => 50.0]], 200);
            }
            if (str_contains($url, '/reports/campaigns')) {
                return Http::response([
                    'summary' => [[
                        '_id' => 'new-job-id-after-retry',
                        'message' => 'Midweek service reminder',
                        'phone' => '0241234567',
                        'date_time' => '2026-08-23 17:02:15', // within ±2 hours
                        'status' => 'delivered',
                        'sent_time' => '2026-08-23 17:02:15',
                    ]],
                ], 200);
            }

            return Http::response(['summary' => []], 200);
        });

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $fresh = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $fresh->status);
        $this->assertNotNull($fresh->mnotify_response);
        $this->assertSame('new-job-id-after-retry', $fresh->mnotify_response['_id']);
    }

    // ─── Graceful Boot Failure ─────────────────────────────────

    /**
     * Confirms that when mNotify is unreachable on boot, the command
     * returns a non-zero exit code but does not throw — the container
     * entrypoint continues to start the web server.
     */
    public function test_boot_reconciliation_fails_gracefully_when_mnotify_unreachable(): void
    {
        // Pre-schedule a delivery that needs reconciliation
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Pending message',
            'scheduled_at' => now()->subDay(),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'unreachable-001',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertExitCode(1);

        // Delivery should remain unchanged (not crashed)
        $this->assertSame(
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            ScheduledSmsDelivery::first()->status
        );
    }

    // ─── Batch Size ────────────────────────────────────────────

    /**
     * Confirms reconciliation handles batches of 50+ messages
     * (realistic for a church with weekly SMS automations).
     */
    public function test_reconciliation_handles_large_batch(): void
    {
        $batchSize = 60;
        $phones = [];

        for ($i = 0; $i < $batchSize; $i++) {
            $phone = '0241'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $phones[] = $phone;

            ScheduledSmsDelivery::create([
                'branch_id' => $this->branch->id,
                'phone' => $phone,
                'message_body' => "Batch message #{$i}",
                'scheduled_at' => now()->subDays(2),
                'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
                'mnotify_job_id' => "batch-{$i}",
            ]);
        }

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/balance')) {
                return Http::response(['summary' => ['balance' => 1000.0]], 200);
            }
            if (str_contains($url, '/reports/campaigns')) {
                // First 30 delivered, rest missing
                $reports = [];
                for ($i = 0; $i < 30; $i++) {
                    $reports[] = [
                        '_id' => "batch-{$i}",
                        'message' => "Batch message #{$i}",
                        'phone' => '0241'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                        'date_time' => now()->subDays(2)->addMinutes($i)->format('Y-m-d H:i:s'),
                        'status' => 'delivered',
                    ];
                }

                return Http::response(['summary' => $reports], 200);
            }

            return Http::response(['summary' => []], 200);
        });

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        // 30 delivered via reports, 30 assumed dispatched (positive balance)
        $this->assertSame($batchSize, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_DISPATCHED)->count());
    }
}
