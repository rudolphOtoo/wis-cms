<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPendingRemoteSchedules;
use App\Exceptions\TransientSmsException;
use App\Jobs\CancelScheduledSmsJob;
use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\Branch;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the mNotify off-loaded SMS scheduling infrastructure.
 *
 * Covers:
 *   1. MnotifySmsService::schedule() — correct API payload & response parsing
 *   2. DispatchScheduledSmsToMnotifyJob — local DB lifecycle
 *   3. CancelScheduledSmsJob — remote cancellation
 *   4. Offline resilience — PendingRemoteSchedule creation on network failure
 *   5. SyncPendingRemoteSchedules command — retry logic
 */
class SmsSchedulingTest extends TestCase
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
    }

    // ─── MnotifySmsService::schedule() ────────────────────────

    public function test_schedule_sends_correct_payload_to_mnotify(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'code' => '2000',
            'summary' => ['id' => '12345'],
        ], 200)]);

        $scheduledAt = Carbon::now()->addDays(3)->hour(7)->minute(0)->second(0);
        $result = (new MnotifySmsService)->schedule('0241234567', 'Happy Birthday!', $scheduledAt);

        $this->assertSame('12345', $result);

        Http::assertSent(function ($request) use ($scheduledAt) {
            return str_contains($request->url(), '/sms/quick')
                && str_contains($request->url(), 'key=test-key')
                && $request['is_schedule'] === true
                && $request['schedule_date'] === $scheduledAt->format('Y-m-d H:i')
                && $request['recipient'] === ['0241234567']
                && $request['message'] === 'Happy Birthday!';
        });
    }

    public function test_schedule_returns_job_id_from_summary(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'summary' => ['id' => '99999'],
        ], 200)]);

        $result = (new MnotifySmsService)->schedule('0241234567', 'Test', now()->addHour());

        $this->assertSame('99999', $result);
    }

    public function test_schedule_returns_job_id_from_top_level_id_fallback(): void
    {
        // Some mNotify responses put id at top level
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'id' => '77777',
        ], 200)]);

        $result = (new MnotifySmsService)->schedule('0241234567', 'Test', now()->addHour());

        $this->assertSame('77777', $result);
    }

    public function test_schedule_returns_null_on_rejection(): void
    {
        Http::fake(['*' => Http::response(['status' => 'failed', 'message' => 'no balance'], 200)]);

        $result = (new MnotifySmsService)->schedule('0241234567', 'Test', now()->addHour());

        $this->assertNull($result);
    }

    public function test_schedule_returns_null_on_http_422(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid'], 422)]);

        $result = (new MnotifySmsService)->schedule('0241234567', 'Test', now()->addHour());

        $this->assertNull($result);
    }

    public function test_schedule_throws_transient_on_http_502(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Bad Gateway'], 502)]);

        $this->expectException(TransientSmsException::class);
        (new MnotifySmsService)->schedule('0241234567', 'Test', now()->addHour());
    }

    public function test_schedule_throws_transient_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(TransientSmsException::class);
        (new MnotifySmsService)->schedule('0241234567', 'Test', now()->addHour());
    }

    public function test_schedule_normalises_phone_number(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'summary' => ['id' => '1']], 200)]);

        (new MnotifySmsService)->schedule('233551112222', 'Hi', now()->addHour());

        Http::assertSent(fn ($request) => $request['recipient'] === ['0551112222']);
    }

    // ─── MnotifySmsService::cancelScheduled() ─────────────────

    public function test_cancel_sends_delete_request_to_mnotify(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = (new MnotifySmsService)->cancelScheduled('12345');

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/scheduled/12345')
                && str_contains($request->url(), 'key=test-key');
        });
    }

    public function test_cancel_returns_false_on_rejection(): void
    {
        Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);

        $result = (new MnotifySmsService)->cancelScheduled('99999');

        $this->assertFalse($result);
    }

    public function test_cancel_throws_transient_on_server_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Bad Gateway'], 502)]);

        $this->expectException(TransientSmsException::class);
        (new MnotifySmsService)->cancelScheduled('12345');
    }

    // ─── MnotifySmsService::updateScheduled() ─────────────────

    public function test_update_sends_post_to_mnotify(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $newTime = now()->addDays(5)->hour(9);
        $result = (new MnotifySmsService)->updateScheduled('12345', '0241234567', 'Updated message', $newTime);

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($newTime) {
            return str_contains($request->url(), '/scheduled/12345')
                && $request['message'] === 'Updated message'
                && $request['schedule_date'] === $newTime->format('Y-m-d H:i');
        });
    }

    // ─── DispatchScheduledSmsToMnotifyJob ─────────────────────

    public function test_job_schedules_sms_and_updates_delivery_record(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'summary' => ['id' => '55555']], 200)]);

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Service reminder',
            'scheduled_at' => now()->addDays(2),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'reminder',
        ]);

        (new DispatchScheduledSmsToMnotifyJob($delivery->id))->handle(app(MnotifySmsService::class));

        $fresh = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $fresh->status);
        $this->assertSame('55555', $fresh->mnotify_job_id);
    }

    public function test_job_marks_failed_when_no_job_id_returned(): void
    {
        Http::fake(['*' => Http::response(['status' => 'failed', 'message' => 'insufficient balance'], 200)]);

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Test',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
        ]);

        (new DispatchScheduledSmsToMnotifyJob($delivery->id))->handle(app(MnotifySmsService::class));

        $this->assertSame(ScheduledSmsDelivery::STATUS_FAILED, $delivery->fresh()->status);
    }

    public function test_job_is_idempotent_for_already_scheduled_delivery(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Test',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => '11111',
        ]);

        Http::fake();

        (new DispatchScheduledSmsToMnotifyJob($delivery->id))->handle(app(MnotifySmsService::class));

        // Should NOT have made any API call
        Http::assertNothingSent();
        $this->assertSame('11111', $delivery->fresh()->mnotify_job_id);
    }

    public function test_job_creates_pending_schedule_on_network_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Birthday greeting',
            'scheduled_at' => now()->addDays(7),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'birthday',
        ]);

        $this->expectException(TransientSmsException::class);

        try {
            (new DispatchScheduledSmsToMnotifyJob($delivery->id))->handle(app(MnotifySmsService::class));
        } catch (TransientSmsException $e) {
            // Verify the pending schedule was created
            $pending = PendingRemoteSchedule::where('scheduled_sms_delivery_id', $delivery->id)->first();
            $this->assertNotNull($pending);
            $this->assertSame(PendingRemoteSchedule::ACTION_SCHEDULE, $pending->action);
            $this->assertSame('Birthday greeting', $pending->payload['message_body']);
            $this->assertSame(0, $pending->attempts);

            throw $e;
        }
    }

    // ─── CancelScheduledSmsJob ────────────────────────────────

    public function test_cancel_job_calls_mnotify_and_updates_local_record(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Test',
            'scheduled_at' => now()->addDays(2),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => '12345',
        ]);

        (new CancelScheduledSmsJob($delivery->id))->handle(app(MnotifySmsService::class));

        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->fresh()->status);

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/scheduled/12345');
        });
    }

    public function test_cancel_job_without_mnotify_id_just_updates_locally(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Test',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            // No mnotify_job_id yet
        ]);

        Http::fake();

        (new CancelScheduledSmsJob($delivery->id))->handle(app(MnotifySmsService::class));

        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_cancel_job_skips_already_dispatched_delivery(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Test',
            'scheduled_at' => now()->subDay(),
            'status' => ScheduledSmsDelivery::STATUS_DISPATCHED,
            'mnotify_job_id' => '12345',
        ]);

        Http::fake();

        (new CancelScheduledSmsJob($delivery->id))->handle(app(MnotifySmsService::class));

        // Should not have called mNotify
        Http::assertNothingSent();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $delivery->fresh()->status);
    }

    // ─── Offline Resilience & Sync ────────────────────────────

    public function test_sync_command_dispatches_pending_schedules(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'summary' => ['id' => '88888']], 200)]);

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Offline test',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
        ]);

        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => [
                'phone' => '0241234567',
                'message_body' => 'Offline test',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'branch_id' => $this->branch->id,
            ],
        ]);

        $this->artisan('sync:pending-schedules', ['--force' => true])
            ->assertSuccessful();

        $fresh = $delivery->fresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $fresh->status);
        $this->assertSame('88888', $fresh->mnotify_job_id);
    }

    public function test_sync_command_skips_completed_items(): void
    {
        Http::fake();

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Already done',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => '99999',
        ]);

        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => [
                'phone' => '0241234567',
                'message_body' => 'Already done',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'branch_id' => $this->branch->id,
            ],
            'status' => PendingRemoteSchedule::STATUS_COMPLETED,
        ]);

        $this->artisan('sync:pending-schedules', ['--force' => true])
            ->expectsOutputToContain('No pending schedules')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_sync_command_retries_exhausted_items_skips(): void
    {
        Http::fake();

        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'payload' => [
                'phone' => '0241234567',
                'message_body' => 'Dead letter',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'branch_id' => $this->branch->id,
            ],
            'attempts' => 5,
            'max_attempts' => 5,
            'status' => PendingRemoteSchedule::STATUS_PENDING,
        ]);

        $this->artisan('sync:pending-schedules', ['--force' => true])
            ->expectsOutputToContain('No pending schedules')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_sync_command_handles_network_failure_gracefully(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Network error');
        });

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Retry fail',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
        ]);

        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => [
                'phone' => '0241234567',
                'message_body' => 'Retry fail',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'branch_id' => $this->branch->id,
            ],
        ]);

        $this->artisan('sync:pending-schedules', ['--force' => true])->assertSuccessful();

        $pending = PendingRemoteSchedule::first();
        $this->assertSame(1, $pending->attempts);
        $this->assertNotNull($pending->last_attempt_at);
    }

    // ─── ScheduledSmsDelivery Model ───────────────────────────

    public function test_scheduled_delivery_status_transitions(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Test',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
        ]);

        $this->assertTrue($delivery->isCancellable());

        $delivery->markScheduledRemote('12345', ['raw' => 'response']);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->fresh()->status);
        $this->assertSame('12345', $delivery->fresh()->mnotify_job_id);
        $this->assertTrue($delivery->fresh()->isCancellable());

        $delivery->markDispatched();
        $this->assertSame(ScheduledSmsDelivery::STATUS_DISPATCHED, $delivery->fresh()->status);
        $this->assertFalse($delivery->fresh()->isCancellable());
    }

    public function test_scheduled_delivery_scopes(): void
    {
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Pending',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
        ]);

        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Scheduled',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
        ]);

        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Cancelled',
            'scheduled_at' => now()->addDay(),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
        ]);

        $this->assertSame(1, ScheduledSmsDelivery::pendingApi()->count());
        $this->assertSame(1, ScheduledSmsDelivery::scheduledRemote()->count());
        $this->assertSame(2, ScheduledSmsDelivery::active()->count());
    }

    // ─── Dry-run mode ─────────────────────────────────────────

    public function test_dry_run_returns_fake_job_id(): void
    {
        config(['services.mnotify.dry_run' => true]);

        $result = (new MnotifySmsService)->schedule('0241234567', 'Test', now()->addHour());

        $this->assertNotNull($result);
        $this->assertStringStartsWith('dry-run-', $result);
        Http::assertNothingSent();
    }

    public function test_dry_run_cancel_returns_true(): void
    {
        config(['services.mnotify.dry_run' => true]);

        $result = (new MnotifySmsService)->cancelScheduled('12345');

        $this->assertTrue($result);
        Http::assertNothingSent();
    }
}
