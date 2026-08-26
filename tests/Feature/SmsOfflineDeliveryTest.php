<?php

namespace Tests\Feature;

use App\Exceptions\TransientSmsException;
use App\Jobs\CancelScheduledSmsJob;
use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\Branch;
use App\Models\Member;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end offline SMS delivery simulation tests.
 *
 * Simulates the full lifecycle: schedule creation → mNotify dispatch →
 * simulated shutdown → boot sync with expiry of stale messages.
 *
 * Also covers fail-safe paths: no-internet queuing and event cancellation.
 */
class SmsOfflineDeliveryTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-08-17 06:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP A → B → C → D: Full Offline Delivery Simulation
    // ═══════════════════════════════════════════════════════════

    public function test_full_offline_lifecycle_birthday_3_days_out(): void
    {
        // ─── Step A: Schedule Creation ─────────────────────────
        // Create a member whose birthday is 3 days from now.
        $birthdayDate = now()->startOfDay()->addDays(3);
        $member = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Kwame',
            'last_name' => 'Asante',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $birthdayDate->format('Y-m-d'),
        ]);

        // ─── Step B: mNotify Dispatch Verification ─────────────
        // Mock mNotify to return a job ID on schedule.
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'code' => '2000',
            'summary' => ['id' => 'mnotify-job-99001'],
        ], 200));

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // Verify mNotify received the POST with is_schedule=true.
        Http::assertSent(function ($request) use ($birthdayDate) {
            $isSchedulePost = str_contains($request->url(), '/sms/quick')
                && $request['is_schedule'] === true
                && $request['schedule_date'] === $birthdayDate->copy()->hour(7)->format('Y-m-d H:i')
                && $request['recipient'] === ['0241234567']
                && $request['message'] !== '';

            return $isSchedulePost;
        });

        // Verify local state tracks the mNotify job ID.
        $delivery = ScheduledSmsDelivery::where('source_type', 'birthday')
            ->where('source_id', $member->id)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
        $this->assertSame('mnotify-job-99001', $delivery->mnotify_job_id);
        $this->assertSame('0241234567', $delivery->phone);
        $this->assertStringContainsString('Kwame', $delivery->message_body);
        $this->assertTrue($delivery->scheduled_at->isFuture());

        // ─── Step C: Simulated Shutdown ────────────────────────
        // Once mnotify_job_id is saved, the message lives on mNotify's
        // cloud servers. Shutting down the local application/Docker
        // containers does not affect it.
        $this->assertSame(1, ScheduledSmsDelivery::where('mnotify_job_id', 'mnotify-job-99001')->count());
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->fresh()->status);

        // ─── Step D: Boot Sync Verification ────────────────────
        // Simulate 5 days passing (desktop was off). Now it boots.
        // The birthday is in the past (5 days after birthday).
        Carbon::setTestNow(now()->addDays(5)->hour(6)->minute(0));

        Http::fake(); // Reset to track new calls

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // The old delivery (scheduled_at in the past) must be expired.
        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $delivery->status);
        $this->assertStringContainsString('Expired', $delivery->error_message);

        // No new birthday delivery created for this member because
        // the birthday already passed this year.
        $this->assertSame(1, ScheduledSmsDelivery::where('source_type', 'birthday')
            ->where('source_id', $member->id)->count());

        // No duplicate HTTP calls for this expired delivery.
        Http::assertNothingSent();
    }

    public function test_full_offline_lifecycle_service_reminder(): void
    {
        // ─── Step A: Schedule a Sunday service reminder ─────────
        $sundayService = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true]
        );

        // Reminder fires Saturday 8 PM for Sunday service.
        ServiceReminderSettings::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $sundayService->id,
            'template' => 'Hello {first_name}! {service_name} tomorrow at {service_time}.',
            'send_day_of_week' => 6, // Saturday
            'send_hour' => 20,
            'service_hour' => 9,
            'service_minute' => 0,
            'is_active' => true,
        ]);

        $member = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'gender' => 'female',
            'status' => 'active',
            'phone' => '0249876543',
        ]);

        // ─── Step B: mNotify Dispatch Verification ─────────────
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'mnotify-job-88001'],
        ], 200)]);

        $this->artisan('sms:sync-rolling-automations', ['--days' => 14, '--force' => true])
            ->assertSuccessful();

        // Verify the Saturday 8 PM reminder was dispatched.
        $reminder = ScheduledSmsDelivery::where('source_type', 'reminder')
            ->where('phone', '0249876543')
            ->first();

        $this->assertNotNull($reminder);
        $this->assertSame('mnotify-job-88001', $reminder->mnotify_job_id);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $reminder->status);
        $this->assertStringContainsString('Ama', $reminder->message_body);

        // Verify payload sent to mNotify.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sms/quick')
                && $request['is_schedule'] === true
                && $request['recipient'] === ['0249876543'];
        });

        // ─── Step C: Simulated Shutdown ────────────────────────
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->mnotify_job_id);

        // ─── Step D: Boot Sync After Weekend ───────────────────
        // Desktop was off from Saturday night through Sunday.
        // Saturday 8 PM is now in the past (Sunday 6 AM).
        Carbon::setTestNow(now()->addDays(6)->hour(6)->minute(0)); // Sunday 6 AM

        Http::fake();

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // Saturday's reminder is expired (scheduled_at in the past).
        $reminder->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $reminder->status);
        $this->assertStringContainsString('Expired', $reminder->error_message);

        // A fresh reminder was created for the NEXT Saturday (idempotent).
        $freshReminder = ScheduledSmsDelivery::where('source_type', 'reminder')
            ->where('phone', '0249876543')
            ->where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)
            ->first();
        $this->assertNotNull($freshReminder);
        $this->assertNotSame($reminder->id, $freshReminder->id);
    }

    // ═══════════════════════════════════════════════════════════
    //  PAYLOAD INSPECTION: JSON structure sent to mNotify
    // ═══════════════════════════════════════════════════════════

    public function test_mnotify_payload_contains_required_fields(): void
    {
        $birthdayDate = now()->startOfDay()->addDays(2);
        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Kofi',
            'last_name' => 'Boateng',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '0245551234',
            'date_of_birth' => $birthdayDate->format('Y-m-d'),
        ]);

        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'payload-test-001'],
        ], 200));

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // Inspect the exact JSON payload structure.
        Http::assertSent(function ($request) use ($birthdayDate) {
            // Skip non-SMS requests (e.g. /balance endpoint)
            if (! str_contains($request->url(), '/sms/quick')) {
                return false;
            }

            // Must POST to /sms/quick with API key.
            $urlOk = str_contains($request->url(), 'key=test-key');

            // Required fields present and correct.
            $fieldsOk = $request['is_schedule'] === true
                && $request['schedule_date'] === $birthdayDate->copy()->hour(7)->format('Y-m-d H:i')
                && is_array($request['recipient'])
                && count($request['recipient']) === 1
                && $request['recipient'][0] === '0245551234'
                && $request['sender'] === 'WIS'
                && is_string($request['message'])
                && strlen($request['message']) > 0;

            return $urlOk && $fieldsOk;
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  FAIL-SAFE: No Internet at Schedule Time
    // ═══════════════════════════════════════════════════════════

    public function test_no_internet_queues_locally_and_retries_on_reconnect(): void
    {
        // ─── Phase 1: No internet — mNotify unreachable ────────
        // MnotifySmsService catches ConnectionException and wraps it
        // in TransientSmsException. The job re-throws that wrapper.
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241112233',
            'message_body' => 'Offline birthday greeting',
            'scheduled_at' => now()->addDays(3),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'birthday',
            'source_id' => '00000000-0000-0000-0000-000000000099',
        ]);

        $this->expectException(TransientSmsException::class);

        try {
            (new DispatchScheduledSmsToMnotifyJob($delivery->id))
                ->handle(app(MnotifySmsService::class));
        } catch (TransientSmsException $e) {
            // Verify: PendingRemoteSchedule was created for retry.
            $pending = PendingRemoteSchedule::where('scheduled_sms_delivery_id', $delivery->id)->first();
            $this->assertNotNull($pending);
            $this->assertSame(PendingRemoteSchedule::ACTION_SCHEDULE, $pending->action);
            $this->assertSame('0241112233', $pending->payload['phone']);
            $this->assertSame(0, $pending->attempts);
            $this->assertSame(PendingRemoteSchedule::STATUS_PENDING, $pending->status);

            // Delivery remains pending_api (not failed — it will retry).
            $this->assertSame(ScheduledSmsDelivery::STATUS_PENDING_API, $delivery->fresh()->status);

            throw $e;
        }
    }

    public function test_sync_pending_schedules_retries_on_reconnect(): void
    {
        // ─── Phase 2: Internet returns — sync retries ──────────
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'reconnected-job-001'],
        ], 200));

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241112233',
            'message_body' => 'Offline birthday greeting',
            'scheduled_at' => now()->addDays(3),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'birthday',
            'source_id' => '00000000-0000-0000-0000-000000000099',
        ]);

        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => [
                'phone' => '0241112233',
                'message_body' => 'Offline birthday greeting',
                'scheduled_at' => now()->addDays(3)->toIso8601String(),
                'branch_id' => $this->branch->id,
            ],
            'error_message' => 'Connection timed out',
        ]);

        $this->artisan('sync:pending-schedules', ['--force' => true])->assertSuccessful();

        // Verify: delivery now scheduled on mNotify.
        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
        $this->assertSame('reconnected-job-001', $delivery->mnotify_job_id);

        // Verify: pending schedule marked completed.
        $pending = PendingRemoteSchedule::where('scheduled_sms_delivery_id', $delivery->id)->first();
        $this->assertSame(PendingRemoteSchedule::STATUS_COMPLETED, $pending->status);

        // Verify: mNotify received the schedule request.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sms/quick')
                && $request['is_schedule'] === true;
        });
    }

    public function test_pending_schedule_retry_loop_with_multiple_failures(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241112233',
            'message_body' => 'Retry test',
            'scheduled_at' => now()->addDays(3),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'birthday',
            'source_id' => '00000000-0000-0000-0000-000000000099',
        ]);

        $pending = PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => [
                'phone' => '0241112233',
                'message_body' => 'Retry test',
                'scheduled_at' => now()->addDays(3)->toIso8601String(),
                'branch_id' => $this->branch->id,
            ],
            'attempts' => 3,
            'max_attempts' => 5,
        ]);

        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            if ($callCount <= 1) {
                throw new ConnectionException('Still offline');
            }

            return Http::response([
                'status' => 'success',
                'summary' => ['id' => 'retry-success-001'],
            ], 200);
        });

        // ─── Run 1: Still offline — attempt fails ──────────────
        $this->artisan('sync:pending-schedules', ['--force' => true])->assertSuccessful();

        $pending->refresh();
        $this->assertSame(4, $pending->attempts);
        $this->assertSame(PendingRemoteSchedule::STATUS_PENDING, $pending->status);
        $this->assertSame(ScheduledSmsDelivery::STATUS_PENDING_API, $delivery->fresh()->status);

        // ─── Run 2: Internet returns — attempt succeeds ────────
        $this->artisan('sync:pending-schedules', ['--force' => true])->assertSuccessful();

        $pending->refresh();
        $this->assertSame(PendingRemoteSchedule::STATUS_COMPLETED, $pending->status);

        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
        $this->assertSame('retry-success-001', $delivery->mnotify_job_id);
    }

    // ═══════════════════════════════════════════════════════════
    //  FAIL-SAFE: Edited / Cancelled Events
    // ═══════════════════════════════════════════════════════════

    public function test_cancelled_event_sends_cancellation_to_mnotify(): void
    {
        // Admin cancels a scheduled event → system must cancel on mNotify.
        Http::fake(fn () => Http::response(['status' => 'success'], 200));

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0249998877',
            'message_body' => 'Meeting reminder',
            'scheduled_at' => now()->addDays(5),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'cancel-test-555',
            'source_type' => 'reminder',
            'source_id' => '00000000-0000-0000-0000-000000000042',
        ]);

        // Simulate admin cancelling → dispatch cancel job.
        (new CancelScheduledSmsJob($delivery->id))
            ->handle(app(MnotifySmsService::class));

        // Verify: local record marked cancelled (remote confirmed).
        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);

        // Verify: DELETE request sent to mNotify.
        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/scheduled/cancel-test-555')
                && str_contains($request->url(), 'key=test-key');
        });
    }

    public function test_cancel_with_network_failure_queues_pending_cancel(): void
    {
        // MnotifySmsService wraps ConnectionException in TransientSmsException.
        Http::fake(fn () => throw new ConnectionException('Network down'));

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0249998877',
            'message_body' => 'Meeting reminder',
            'scheduled_at' => now()->addDays(5),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'cancel-fail-777',
            'source_type' => 'reminder',
        ]);

        $this->expectException(TransientSmsException::class);

        try {
            (new CancelScheduledSmsJob($delivery->id))
                ->handle(app(MnotifySmsService::class));
        } catch (TransientSmsException $e) {
            // Pending cancel queued for retry.
            $pending = PendingRemoteSchedule::where('scheduled_sms_delivery_id', $delivery->id)->first();
            $this->assertNotNull($pending);
            $this->assertSame(PendingRemoteSchedule::ACTION_CANCEL, $pending->action);
            $this->assertSame('cancel-fail-777', $pending->payload['mnotify_job_id']);

            throw $e;
        }
    }

    public function test_sync_retries_pending_cancel_on_reconnect(): void
    {
        Http::fake(fn () => Http::response(['status' => 'success'], 200));

        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0249998877',
            'message_body' => 'Meeting reminder',
            'scheduled_at' => now()->addDays(5),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'cancel-retry-888',
            'source_type' => 'reminder',
        ]);

        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_CANCEL,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => ['mnotify_job_id' => 'cancel-retry-888'],
            'error_message' => 'Network down',
        ]);

        $this->artisan('sync:pending-schedules', ['--force' => true])->assertSuccessful();

        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/scheduled/cancel-retry-888');
        });
    }

    public function test_cancel_without_mnotify_id_just_updates_locally(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0249998877',
            'message_body' => 'Too early to cancel',
            'scheduled_at' => now()->addDays(5),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            // No mnotify_job_id — never reached mNotify.
        ]);

        Http::fake();

        (new CancelScheduledSmsJob($delivery->id))
            ->handle(app(MnotifySmsService::class));

        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════════
    //  IDEMPOTENCY: No Duplicate After Boot Sync
    // ═══════════════════════════════════════════════════════════

    public function test_boot_sync_does_not_duplicate_existing_scheduled_jobs(): void
    {
        $today = now()->startOfDay();

        // Pre-create 3 members with birthdays in the next 3 days.
        $members = [];
        for ($i = 1; $i <= 3; $i++) {
            $date = $today->copy()->addDays($i);
            $members[] = Member::create([
                'branch_id' => $this->branch->id,
                'first_name' => "Dup{$i}",
                'last_name' => 'Test',
                'gender' => 'male',
                'status' => 'active',
                'phone' => "024123450{$i}",
                'date_of_birth' => $date->format('Y-m-d'),
            ]);
        }

        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-dup-001'],
        ], 200));

        // First sync — creates 3 deliveries.
        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();
        $this->assertSame(3, ScheduledSmsDelivery::count());

        // Reset HTTP tracker.
        Http::fake();

        // Second sync — should create zero new deliveries.
        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();
        $this->assertSame(3, ScheduledSmsDelivery::count());
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════════
    //  EXPIRY: Past-Due Messages Marked Expired, Not Sent
    // ═══════════════════════════════════════════════════════════

    public function test_expired_messages_are_never_sent_on_reboot(): void
    {
        // Create 2 deliveries that are in the past (missed during offline).
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241000001',
            'message_body' => 'Stale birthday 1',
            'scheduled_at' => now()->subDays(3),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'birthday',
            'source_id' => '00000000-0000-0000-0000-000000000001',
        ]);

        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241000002',
            'message_body' => 'Stale birthday 2',
            'scheduled_at' => now()->subDays(1),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'stale-job-002',
            'source_type' => 'birthday',
            'source_id' => '00000000-0000-0000-0000-000000000002',
        ]);

        // One future delivery that should NOT be expired.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241000003',
            'message_body' => 'Valid future birthday',
            'scheduled_at' => now()->addDays(2),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'valid-job-003',
            'source_type' => 'birthday',
            'source_id' => '00000000-0000-0000-0000-000000000003',
        ]);

        Http::fake();

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])->assertSuccessful();

        // Past-due deliveries expired.
        $expired = ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_CANCELLED)->get();
        $this->assertCount(2, $expired);
        $expired->each(fn ($d) => $this->assertStringContainsString('Expired', $d->error_message));

        // Future delivery untouched.
        $valid = ScheduledSmsDelivery::where('mnotify_job_id', 'valid-job-003')->first();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $valid->status);

        // No mNotify calls for expired messages.
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════════
    //  CONTAINER SHUTDOWN: Jobs Remain on mNotify
    // ═══════════════════════════════════════════════════════════

    public function test_all_scheduled_jobs_survive_local_container_shutdown(): void
    {
        $today = now()->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->addDays($i);
            Member::create([
                'branch_id' => $this->branch->id,
                'first_name' => "Survive{$i}",
                'last_name' => 'Test',
                'gender' => 'male',
                'status' => 'active',
                'phone' => "02476543{$i}0",
                'date_of_birth' => $date->format('Y-m-d'),
            ]);
        }

        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'survive-job-'.bin2hex(random_bytes(3))],
        ], 200));

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // All 7 jobs are safely queued on mNotify's mock server.
        $this->assertSame(7, ScheduledSmsDelivery::count());
        $this->assertSame(0, ScheduledSmsDelivery::pendingApi()->count());
        $this->assertSame(7, ScheduledSmsDelivery::scheduledRemote()->count());
        $this->assertSame(7, ScheduledSmsDelivery::whereNotNull('mnotify_job_id')->count());

        // Every delivery has a valid future scheduled_at.
        ScheduledSmsDelivery::all()->each(function (ScheduledSmsDelivery $d) {
            $this->assertTrue($d->scheduled_at->isFuture());
        });

        // Container shutdown simulation (reset HTTP tracker).
        Http::fake();

        // After shutdown, no additional API calls can be made.
        // The7 jobs remain safely on mNotify.
        Http::assertNothingSent();
    }
}
