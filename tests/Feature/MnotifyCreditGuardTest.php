<?php

namespace Tests\Feature;

use App\Exceptions\TransientSmsException;
use App\Models\Branch;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\SystemAlert;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the Pre-Sync Credit Guard and Post-Delivery Reconciliation.
 *
 * Phase 1: Credit Guard
 *   - Verifies sms:sync-rolling-automations aborts when credits are insufficient
 *   - Verifies execution proceeds when credits are sufficient
 *   - Verifies fallback behavior on balance check failures
 *
 * Phase 2: Reconciliation
 *   - Verifies past-due deliveries are accurately mapped from mNotify status
 *   - Verifies insufficient balance → failed_provider status
 *   - Verifies purged jobs (after scheduled_at) → dispatched
 *   - Verifies still-scheduled jobs remain unchanged
 */
class MnotifyCreditGuardTest extends TestCase
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
            'church.birthday.enabled' => true,
        ]);

        $this->branch = Branch::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-20 06:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Phase 1: Credit Guard ────────────────────────────────

    public function test_sync_aborts_when_credits_are_insufficient(): void
    {
        $today = now()->startOfDay();
        $date = $today->copy()->addDays(3);

        // Create a member with a birthday in 3 days
        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Test',
            'last_name' => 'Member',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        // First request: balance check returns low balance (0.5 < 1 required)
        // Second request: sms/quick (should never be reached)
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;

            if (str_contains($request->url(), '/balance')) {
                return Http::response(['summary' => ['balance' => 0.5]], 200);
            }

            // If we reach here, the credit guard failed
            return Http::response(['status' => 'success', 'summary' => ['id' => 'should-not-exist']], 200);
        });

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertExitCode(1); // FAILURE due to insufficient credits

        // Deliveries should be created but marked as failed
        $this->assertGreaterThan(0, ScheduledSmsDelivery::count());
        $this->assertSame(0, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)->count());
        $this->assertSame(
            ScheduledSmsDelivery::count(),
            ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_FAILED)->count()
        );

        // Verify error message contains credit info
        $delivery = ScheduledSmsDelivery::first();
        $this->assertStringContainsString('Insufficient mNotify credits', $delivery->error_message);
        $this->assertStringContainsString('available', $delivery->error_message);
        $this->assertStringContainsString('required', $delivery->error_message);

        // Verify no SMS was pushed to mNotify (all remain pending_api → failed, none scheduled_remote)
        $this->assertSame(0, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)->count());
    }

    public function test_sync_proceeds_when_credits_are_sufficient(): void
    {
        $today = now()->startOfDay();
        $date = $today->copy()->addDays(3);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Wealthy',
            'last_name' => 'Account',
            'gender' => 'female',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/balance')) {
                return Http::response(['summary' => ['balance' => 500.0]], 200);
            }

            return Http::response([
                'status' => 'success',
                'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
            ], 200);
        });

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // Should have dispatched successfully
        $this->assertSame(1, ScheduledSmsDelivery::count());
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, ScheduledSmsDelivery::first()->status);
        $this->assertNotNull(ScheduledSmsDelivery::first()->mnotify_job_id);
    }

    public function test_sync_proceeds_on_balance_check_network_failure(): void
    {
        $today = now()->startOfDay();
        $date = $today->copy()->addDays(3);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Fallback',
            'last_name' => 'Member',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        $balanceCallCount = 0;
        Http::fake(function ($request) use (&$balanceCallCount) {
            if (str_contains($request->url(), '/balance')) {
                $balanceCallCount++;

                return ConnectionException::class
                    ? throw new ConnectionException('Connection timed out')
                    : Http::response([], 500);
            }

            return Http::response([
                'status' => 'success',
                'summary' => ['id' => 'job-ok'],
            ], 200);
        });

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // Should still dispatch despite balance check failure
        $this->assertSame(1, ScheduledSmsDelivery::count());
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, ScheduledSmsDelivery::first()->status);
    }

    public function test_sync_proceeds_when_balance_returns_null(): void
    {
        $today = now()->startOfDay();
        $date = $today->copy()->addDays(3);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Null',
            'last_name' => 'Balance',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/balance')) {
                // Response with no balance field
                return Http::response(['status' => 'success'], 200);
            }

            return Http::response([
                'status' => 'success',
                'summary' => ['id' => 'job-ok'],
            ], 200);
        });

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, ScheduledSmsDelivery::count());
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, ScheduledSmsDelivery::first()->status);
    }

    public function test_credit_guard_estimates_multi_part_sms(): void
    {
        $sms = new MnotifySmsService;

        // Single-part message (< 160 chars)
        $shortMessages = ['Hello!', 'Service tonight at 7pm'];
        $this->assertSame(2, $sms->estimateCredits($shortMessages));

        // Multi-part message (200 chars → 2 segments)
        $longMessage = str_repeat('a', 200);
        $this->assertSame(2, $sms->estimateCredits([$longMessage]));

        // Mixed batch
        $this->assertSame(3, $sms->estimateCredits(['Hello!', $longMessage]));
    }

    public function test_check_balance_returns_numeric_balance(): void
    {
        Http::fake([
            str_contains(config('services.mnotify.base_url'), 'api.mnotify.com')
                ? '*' : '*' => Http::response(['summary' => ['balance' => 152.5]], 200),
        ]);

        $result = (new MnotifySmsService)->checkBalance();

        $this->assertSame(152.5, $result);
    }

    public function test_check_balance_returns_null_when_no_api_key(): void
    {
        config(['services.mnotify.api_key' => null]);

        $result = (new MnotifySmsService)->checkBalance();

        $this->assertNull($result);
    }

    public function test_check_balance_returns_null_on_http_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $result = (new MnotifySmsService)->checkBalance();

        $this->assertNull($result);
    }

    public function test_check_balance_throws_transient_on_server_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Bad Gateway'], 502)]);

        $this->expectException(TransientSmsException::class);
        (new MnotifySmsService)->checkBalance();
    }

    public function test_check_balance_throws_transient_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(TransientSmsException::class);
        (new MnotifySmsService)->checkBalance();
    }

    // ─── Phase 2: Reconciliation ──────────────────────────────

    public function test_reconciliation_marks_sent_jobs_as_dispatched(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Service reminder',
            'scheduled_at' => now()->subDay(),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'remote-123',
        ]);

        Http::fake([
            '*' => function ($request) {
                $url = $request->url();
                if (str_contains($url, '/balance')) {
                    return Http::response(['summary' => ['balance' => 500.0]], 200);
                }
                if (str_contains($url, '/reports/campaigns')) {
                    return Http::response(['summary' => []], 200);
                }

                return Http::response([
                    'summary' => [[
                        '_id' => 'remote-123',
                        'message' => 'Service reminder',
                        'date_time' => now()->subDay()->format('Y-m-d H:i:s'),
                        'status' => 'sent',
                    ]],
                ], 200);
            },
        ]);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $delivery->fresh()->status);
    }

    public function test_reconciliation_marks_insufficient_balance_as_failed_provider(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Happy Birthday!',
            'scheduled_at' => now()->subDay(),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED, // was marked expired offline
        ]);

        Http::fake([
            '*' => function ($request) {
                $url = $request->url();
                if (str_contains($url, '/balance')) {
                    return Http::response(['summary' => ['balance' => 500.0]], 200);
                }
                if (str_contains($url, '/reports/campaigns')) {
                    return Http::response(['summary' => []], 200);
                }

                return Http::response([
                    'summary' => [[
                        '_id' => 'remote-456',
                        'message' => 'Happy Birthday!',
                        'date_time' => now()->subDay()->format('Y-m-d H:i:s'),
                        'status' => 'failed',
                        'message_detail' => 'no balance',
                    ]],
                ], 200);
            },
        ]);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $fresh = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_FAILED_PROVIDER, $fresh->status);
        $this->assertNotNull($fresh->failure_reason);
    }

    public function test_reconciliation_marks_purged_future_job_as_dispatched(): void
    {
        // A past-due job that's not in mNotify's list = purged after execution
        // Phase 2: with positive balance, missing jobs are assumed dispatched
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Old message that was sent',
            'scheduled_at' => now()->subDays(3),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
        ]);

        Http::fake([
            '*' => function ($request) {
                $url = $request->url();
                if (str_contains($url, '/balance')) {
                    return Http::response(['summary' => ['balance' => 150.0]], 200);
                }
                if (str_contains($url, '/reports/campaigns')) {
                    return Http::response(['summary' => []], 200);
                }

                // Empty schedule — job was purged
                return Http::response(['summary' => []], 200);
            },
        ]);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $delivery->fresh()->status);
    }

    public function test_reconciliation_leaves_scheduled_jobs_unchanged(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Future greeting',
            'scheduled_at' => now()->addDays(5),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'future-job',
        ]);

        Http::fake([
            '*' => function ($request) {
                $url = $request->url();
                if (str_contains($url, '/balance')) {
                    return Http::response(['summary' => ['balance' => 500.0]], 200);
                }
                if (str_contains($url, '/reports/campaigns')) {
                    return Http::response(['summary' => []], 200);
                }

                return Http::response([
                    'summary' => [[
                        '_id' => 'future-job',
                        'message' => 'Future greeting',
                        'date_time' => now()->addDays(5)->format('Y-m-d H:i:s'),
                        'status' => 'scheduled',
                    ]],
                ], 200);
            },
        ]);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->fresh()->status);
    }

    public function test_reconciliation_handles_network_failure_gracefully(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Network error');
        });

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertExitCode(1); // FAILURE due to unreachable mNotify
    }

    public function test_reconciliation_skips_in_testing_without_force(): void
    {
        Http::fake();

        config(['services.mnotify.dry_run' => true]);

        $this->artisan('sms:reconcile-remote-statuses')
            ->expectsOutputToContain('Skipping')
            ->assertSuccessful();
    }

    public function test_reconciliation_skips_without_api_key(): void
    {
        config(['services.mnotify.api_key' => null]);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->expectsOutputToContain('API key not configured')
            ->assertExitCode(1);
    }

    // ─── Phase 1 Expansion: UCS-2 / Unicode Encoding Detection ─

    public function test_is_ucs2_returns_true_for_emoji(): void
    {
        $sms = new MnotifySmsService;

        // Church SMS with emojis
        $this->assertTrue($sms->isUcs2Message('Happy Birthday! 🎂🎉'));
        $this->assertTrue($sms->isUcs2Message('Join us for prayer 🙏🏽 this Wednesday'));
        $this->assertTrue($sms->isUcs2Message('Welcome to our service ⛪'));
        $this->assertTrue($sms->isUcs2Message('God bless you ❤️'));
    }

    public function test_is_ucs2_returns_false_for_gsm(): void
    {
        $sms = new MnotifySmsService;

        // Pure ASCII messages (GSM 7-bit)
        $this->assertFalse($sms->isUcs2Message('Hello, service is at 7pm tonight'));
        $this->assertFalse($sms->isUcs2Message('Thank you for your giving!'));
        $this->assertFalse($sms->isUcs2Message('1234567890'));
        $this->assertFalse($sms->isUcs2Message('Test@email.com'));
    }

    public function test_is_ucs2_returns_true_for_non_latin(): void
    {
        $sms = new MnotifySmsService;

        // Non-Latin scripts (require UCS-2)
        $this->assertTrue($sms->isUcs2Message('服務開始時間'));
        $this->assertTrue($sms->isUcs2Message('Богослужение'));
    }

    public function test_estimate_credits_uses_ucs2_limits_for_unicode(): void
    {
        $sms = new MnotifySmsService;

        // UCS-2 single segment: up to 70 chars
        $shortUnicode = str_repeat('🙏', 10); // 10 emojis = 10 chars, fits in 1 UCS-2 segment
        $this->assertSame(1, $sms->estimateCredits([$shortUnicode]));

        // UCS-2 single segment: exactly 70 chars
        $ucs2Exact = str_repeat('a🙏', 35); // 70 chars total, all UCS-2
        $this->assertSame(1, $sms->estimateCredits([$ucs2Exact]));

        // UCS-2 multi-part: 71 chars = 2 segments (70 + 1, ceil(71/67)=2)
        $ucs2Long = str_repeat('a🙏', 35).'b'; // 71 chars
        $this->assertSame(2, $sms->estimateCredits([$ucs2Long]));

        // Long UCS-2: 200 chars = ceil(200/67) = 3 segments
        $ucs2VeryLong = str_repeat('🙏', 200);
        $this->assertSame(3, $sms->estimateCredits([$ucs2VeryLong]));
    }

    public function test_estimate_credits_uses_gsm_limits_for_ascii(): void
    {
        $sms = new MnotifySmsService;

        // GSM single segment: up to 160 chars
        $shortGsm = str_repeat('a', 100);
        $this->assertSame(1, $sms->estimateCredits([$shortGsm]));

        // GSM single segment: exactly 160 chars
        $gsmExact = str_repeat('a', 160);
        $this->assertSame(1, $sms->estimateCredits([$gsmExact]));

        // GSM multi-part: 161 chars = 2 segments (ceil(161/153)=2)
        $gsmLong = str_repeat('a', 161);
        $this->assertSame(2, $sms->estimateCredits([$gsmLong]));

        // Long GSM: 500 chars = ceil(500/153) = 4 segments
        $gsmVeryLong = str_repeat('a', 500);
        $this->assertSame(4, $sms->estimateCredits([$gsmVeryLong]));
    }

    public function test_estimate_credits_mixed_batch_gsm_and_ucs2(): void
    {
        $sms = new MnotifySmsService;

        // Mix of GSM and UCS-2 messages
        $gsmMsg = str_repeat('a', 100); // 1 GSM segment
        $ucs2Msg = str_repeat('🙏', 10); // 1 UCS-2 segment (10 chars <= 70)
        $this->assertSame(2, $sms->estimateCredits([$gsmMsg, $ucs2Msg]));
    }

    // ─── Phase 2 Expansion: Reconciliation False-Positive Prevention ─

    public function test_reconciliation_marks_missing_job_as_failed_when_balance_zero(): void
    {
        // Simulates the exact incident scenario: job missing from mNotify + zero balance
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Midweek service reminder',
            'scheduled_at' => now()->subDays(3),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
        ]);

        Http::fake([
            '*' => function ($request) {
                $url = $request->url();
                if (str_contains($url, '/balance')) {
                    return Http::response(['summary' => ['balance' => 0]], 200);
                }
                if (str_contains($url, '/reports/campaigns')) {
                    return Http::response(['summary' => []], 200);
                }

                // /scheduled — empty (job was rejected, never appeared)
                return Http::response(['summary' => []], 200);
            },
        ]);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $fresh = $delivery->fresh();
        // Should NOT be marked as dispatched — balance was depleted
        $this->assertSame(ScheduledSmsDelivery::STATUS_FAILED_PROVIDER, $fresh->status);
        $this->assertStringContainsString('UNCONFIRMED_POSSIBLE_CREDIT_DEPLETION', $fresh->failure_reason);
    }

    public function test_reconciliation_marks_missing_job_as_dispatched_when_balance_positive(): void
    {
        // Missing job but balance is positive → less likely credit depletion
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Old message',
            'scheduled_at' => now()->subDays(7),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
        ]);

        Http::fake([
            '*' => function ($request) {
                $url = $request->url();
                if (str_contains($url, '/balance')) {
                    return Http::response(['summary' => ['balance' => 150.0]], 200);
                }
                if (str_contains($url, '/reports/campaigns')) {
                    return Http::response(['summary' => []], 200);
                }

                return Http::response(['summary' => []], 200);
            },
        ]);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $fresh = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $fresh->status);
        $this->assertStringContainsString('ASSUMED_DISPATCHED', $fresh->error_message);
    }

    public function test_reconciliation_confirms_dispatch_via_delivery_report(): void
    {
        // Job missing from schedule but confirmed in delivery reports
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Service reminder',
            'scheduled_at' => now()->subDays(2),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
            'mnotify_job_id' => 'report-789',
        ]);

        Http::fake([
            '*' => function ($request) {
                $url = $request->url();
                if (str_contains($url, '/balance')) {
                    return Http::response(['summary' => ['balance' => 50.0]], 200);
                }
                if (str_contains($url, '/reports/campaigns')) {
                    return Http::response([
                        'summary' => [[
                            '_id' => 'report-789',
                            'message' => 'Service reminder',
                            'phone' => '0241234567',
                            'date_time' => now()->subDays(2)->format('Y-m-d H:i:s'),
                            'status' => 'sent',
                        ]],
                    ], 200);
                }

                return Http::response(['summary' => []], 200);
            },
        ]);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $fresh = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $fresh->status);
    }

    // ─── Phase 3: System Alert Generation ─

    public function test_credit_depletion_creates_system_alert(): void
    {
        $today = now()->startOfDay();
        $date = $today->copy()->addDays(3);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Alert',
            'last_name' => 'Member',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/balance')) {
                return Http::response(['summary' => ['balance' => 0.5]], 200);
            }

            return Http::response(['status' => 'success'], 200);
        });

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertExitCode(1);

        // System alert should have been created
        $alert = SystemAlert::where('type', SystemAlert::TYPE_CREDIT_DEPLETION)->first();
        $this->assertNotNull($alert);
        $this->assertSame('SMS Credits Depleted', $alert->title);
        $this->assertStringContainsString('GH₵', $alert->message);
        $this->assertNull($alert->acknowledged_at);
        $this->assertNotNull($alert->meta);
        $this->assertArrayHasKey('balance', $alert->meta);
        $this->assertArrayHasKey('credits_needed', $alert->meta);
        $this->assertArrayHasKey('delivery_count', $alert->meta);
    }

    public function test_no_system_alert_when_credits_sufficient(): void
    {
        $today = now()->startOfDay();
        $date = $today->copy()->addDays(3);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Plenty',
            'last_name' => 'Credits',
            'gender' => 'female',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/balance')) {
                return Http::response(['summary' => ['balance' => 500.0]], 200);
            }

            return Http::response([
                'status' => 'success',
                'summary' => ['id' => 'job-ok'],
            ], 200);
        });

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // No credit depletion alert should exist
        $this->assertSame(0, SystemAlert::where('type', SystemAlert::TYPE_CREDIT_DEPLETION)->count());
    }
}
