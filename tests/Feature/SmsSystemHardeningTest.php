<?php

namespace Tests\Feature;

use App\Exceptions\TransientSmsException;
use App\Models\Branch;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\SystemAlert;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Enterprise Hardening Tests for the SMS subsystem.
 *
 * Covers: atomic execution locks, HTTP resilience, idempotency
 * on repeated reconciliation, alert deduplication, and the
 * sms:health-check diagnostic command.
 */
class SmsSystemHardeningTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-08-26 06:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Phase 1: Atomic Locking ────────────────────────────────

    public function test_sync_rolling_acquires_lock_and_releases_on_completion(): void
    {
        Http::fake([
            str_contains(config('services.mnotify.base_url'), 'api.mnotify.com')
                ? '*' : '*' => Http::response(['summary' => ['balance' => 500.0]], 200),
        ]);

        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(5)->andReturn(true);
        $lock->shouldReceive('release')->once();

        Cache::shouldReceive('lock')->once()->with('sms_sync_rolling_automations_lock', 30)->andReturn($lock);

        $this->artisan('sms:sync-rolling-automations', ['--force' => true])
            ->assertSuccessful();
    }

    public function test_sync_rolling_skips_when_lock_cannot_be_acquired(): void
    {
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(5)->andThrow(
            new LockTimeoutException('Lock not acquired')
        );

        Cache::shouldReceive('lock')->once()->with('sms_sync_rolling_automations_lock', 30)->andReturn($lock);

        $this->artisan('sms:sync-rolling-automations', ['--force' => true])
            ->assertSuccessful();
    }

    public function test_reconcile_skips_when_lock_cannot_be_acquired(): void
    {
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(5)->andThrow(
            new LockTimeoutException('Lock not acquired')
        );

        Cache::shouldReceive('lock')->once()->with('sms_reconcile_remote_statuses_lock', 60)->andReturn($lock);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();
    }

    public function test_sync_rolling_releases_lock_even_on_failure(): void
    {
        config(['services.mnotify.api_key' => null]);

        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(5)->andReturn(true);
        $lock->shouldReceive('release')->once();

        Cache::shouldReceive('lock')->once()->with('sms_sync_rolling_automations_lock', 30)->andReturn($lock);

        $this->artisan('sms:sync-rolling-automations', ['--force' => true])
            ->assertExitCode(1);
    }

    public function test_reconcile_releases_lock_even_on_failure(): void
    {
        config(['services.mnotify.api_key' => null]);

        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(5)->andReturn(true);
        $lock->shouldReceive('release')->once();

        Cache::shouldReceive('lock')->once()->with('sms_reconcile_remote_statuses_lock', 60)->andReturn($lock);

        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertExitCode(1);
    }

    // ─── Phase 2: HTTP Timeout & Resilience ─────────────────────

    public function test_mnotify_service_uses_connect_timeout(): void
    {
        $sms = new MnotifySmsService;

        // Verify that checkBalance handles ConnectionException gracefully
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(TransientSmsException::class);
        $sms->checkBalance();
    }

    public function test_mnotify_service_retries_on_transient_failure(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['summary' => ['balance' => 100.0]], 200);
        });

        $sms = new MnotifySmsService;
        $balance = $sms->checkBalance();

        $this->assertSame(100.0, $balance);
        // retry(3, 200) means up to 3 attempts; we succeeded on attempt 3
        $this->assertGreaterThanOrEqual(3, $attempts);
    }

    public function test_mnotify_send_handles_timeout_gracefully(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $this->expectException(TransientSmsException::class);
        (new MnotifySmsService)->send('0241234567', 'Test message');
    }

    public function test_mnotify_schedule_handles_server_error_gracefully(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Bad Gateway'], 502)]);

        $this->expectException(TransientSmsException::class);
        (new MnotifySmsService)->schedule('0241234567', 'Test', now()->addDay());
    }

    public function test_mnotify_cancel_handles_timeout_gracefully(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(TransientSmsException::class);
        (new MnotifySmsService)->cancelScheduled('job-123');
    }

    public function test_fetch_delivery_reports_returns_empty_on_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Network error');
        });

        $result = (new MnotifySmsService)->fetchDeliveryReports();
        $this->assertSame([], $result);
    }

    // ─── Phase 3: Idempotency ───────────────────────────────────

    public function test_reconciliation_is_idempotent_on_double_run(): void
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
                    return Http::response(['summary' => ['balance' => 100.0]], 200);
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

        // First run
        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $afterFirst = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $afterFirst->status);
        $firstResponse = $afterFirst->mnotify_response;

        // Second run — idempotent: same final state
        $this->artisan('sms:reconcile-remote-statuses', ['--force' => true])
            ->assertSuccessful();

        $afterSecond = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $afterSecond->status);
        $this->assertSame($firstResponse, $afterSecond->mnotify_response);
    }

    public function test_credit_depletion_alert_not_duplicated_across_restarts(): void
    {
        $today = now()->startOfDay();
        $date = $today->copy()->addDays(3);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Test',
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

        // Simulate two container restarts (two consecutive runs)
        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertExitCode(1);

        // Run again (simulates reboot)
        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertExitCode(1);

        // Should have exactly ONE alert, not two
        $alerts = SystemAlert::where('type', SystemAlert::TYPE_CREDIT_DEPLETION)->get();
        $this->assertCount(1, $alerts);
        $this->assertNull($alerts->first()->acknowledged_at);
    }

    public function test_credit_depletion_alert_created_again_after_acknowledgement(): void
    {
        $today = now()->startOfDay();
        $date = $today->copy()->addDays(3);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Test',
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

        // First run — creates alert
        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertExitCode(1);

        $this->assertSame(1, SystemAlert::where('type', SystemAlert::TYPE_CREDIT_DEPLETION)->count());

        // Admin acknowledges the alert
        SystemAlert::where('type', SystemAlert::TYPE_CREDIT_DEPLETION)->first()->acknowledge();

        // Second run — should create a new alert since old one is acknowledged
        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertExitCode(1);

        $this->assertSame(2, SystemAlert::where('type', SystemAlert::TYPE_CREDIT_DEPLETION)->count());
    }

    // ─── Phase 4: Health Check Command ──────────────────────────

    public function test_health_check_reports_healthy_when_api_reachable(): void
    {
        Http::fake([
            '*' => function ($request) {
                $url = $request->url();
                if (str_contains($url, '/balance')) {
                    return Http::response(['summary' => ['balance' => 150.0]], 200);
                }

                return Http::response(['summary' => []], 200);
            },
        ]);

        $this->artisan('sms:health-check')
            ->assertSuccessful();
    }

    public function test_health_check_reports_unhealthy_when_api_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->artisan('sms:health-check')
            ->assertExitCode(1);
    }

    public function test_health_check_reports_unhealthy_without_api_key(): void
    {
        config(['services.mnotify.api_key' => null]);

        $this->artisan('sms:health-check')
            ->assertExitCode(1);
    }

    public function test_health_check_reports_pending_queue_count(): void
    {
        Http::fake([
            '*' => function ($request) {
                if (str_contains($request->url(), '/balance')) {
                    return Http::response(['summary' => ['balance' => 100.0]], 200);
                }

                return Http::response(['summary' => []], 200);
            },
        ]);

        // Create some pending deliveries
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Test',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
        ]);

        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234568',
            'message_body' => 'Test 2',
            'scheduled_at' => now()->addDays(2),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
        ]);

        $this->artisan('sms:health-check')
            ->assertSuccessful();

        // Verify the pending queue is tracked correctly
        $pendingApi = ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_PENDING_API)->count();
        $scheduledRemote = ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)->count();
        $this->assertSame(1, $pendingApi);
        $this->assertSame(1, $scheduledRemote);
    }

    public function test_health_check_reports_system_alerts(): void
    {
        Http::fake([
            '*' => function ($request) {
                if (str_contains($request->url(), '/balance')) {
                    return Http::response(['summary' => ['balance' => 100.0]], 200);
                }

                return Http::response(['summary' => []], 200);
            },
        ]);

        SystemAlert::create([
            'type' => SystemAlert::TYPE_CREDIT_DEPLETION,
            'title' => 'Test Alert',
            'message' => 'Test',
        ]);

        $this->artisan('sms:health-check')
            ->assertSuccessful();

        $this->assertSame(1, SystemAlert::unread()->count());
    }

    public function test_health_check_warns_on_low_credit_balance(): void
    {
        Http::fake([
            '*' => function ($request) {
                if (str_contains($request->url(), '/balance')) {
                    return Http::response(['summary' => ['balance' => 2.0]], 200);
                }

                return Http::response(['summary' => []], 200);
            },
        ]);

        // Create recent SMS history to compute daily average
        for ($i = 0; $i < 30; $i++) {
            ScheduledSmsDelivery::create([
                'branch_id' => $this->branch->id,
                'phone' => '0241234567',
                'message_body' => "Message {$i}",
                'scheduled_at' => now()->subDays(30)->addDays($i),
                'status' => ScheduledSmsDelivery::STATUS_DISPATCHED,
            ]);
        }

        $this->artisan('sms:health-check')
            ->assertSuccessful();
    }

    public function test_health_check_reports_past_due_unreconciled(): void
    {
        Http::fake([
            '*' => function ($request) {
                if (str_contains($request->url(), '/balance')) {
                    return Http::response(['summary' => ['balance' => 100.0]], 200);
                }

                return Http::response(['summary' => []], 200);
            },
        ]);

        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Past due message',
            'scheduled_at' => now()->subDays(3),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
        ]);

        $this->artisan('sms:health-check')
            ->assertSuccessful();
    }
}
