<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the rolling SMS pre-scheduler (sms:sync-rolling-automations).
 *
 * Verifies that dynamic automations (birthdays, service reminders)
 * are offloaded to mNotify's remote scheduling API so they deliver
 * even when the local church desktop is powered off.
 */
class SmsOfflineAutomationTest extends TestCase
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

    // ─── Birthday Pre-Scheduling ──────────────────────────────

    public function test_birthday_greetings_are_pre_scheduled_to_mnotify(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
        ], 200));

        $today = now()->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->addDays($i);
            Member::create([
                'branch_id' => $this->branch->id,
                'first_name' => "Birthday{$i}",
                'last_name' => 'Test',
                'gender' => 'male',
                'status' => 'active',
                'phone' => '024123456'.($i + 1),
                'date_of_birth' => $date->format('Y-m-d'),
            ]);
        }

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(7, ScheduledSmsDelivery::count());

        ScheduledSmsDelivery::all()->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
            $this->assertNotNull($delivery->mnotify_job_id);
            $this->assertSame('birthday', $delivery->source_type);
            $this->assertTrue($delivery->scheduled_at->isFuture());
            $this->assertNotEmpty($delivery->message_body);
            $this->assertStringContainsString('Birthday', $delivery->message_body);
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sms/quick')
                && $request['is_schedule'] === true
                && $request['recipient'] !== [];
        });
    }

    public function test_idempotency_prevents_duplicate_scheduled_requests(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
        ], 200));

        $today = now()->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->addDays($i);
            Member::create([
                'branch_id' => $this->branch->id,
                'first_name' => "Member{$i}",
                'last_name' => 'Test',
                'gender' => 'male',
                'status' => 'active',
                'phone' => '024123456'.($i + 1),
                'date_of_birth' => $date->format('Y-m-d'),
            ]);
        }

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])->assertSuccessful();
        $this->assertSame(7, ScheduledSmsDelivery::count());

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])->assertSuccessful();
        $this->assertSame(7, ScheduledSmsDelivery::count());
    }

    // ─── Catch-up & Expiry Guards ─────────────────────────────

    public function test_past_due_deliveries_are_expired_on_sync(): void
    {
        $pastDelivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Old birthday greeting',
            'scheduled_at' => now()->subDays(2),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'birthday',
            'source_id' => '00000000-0000-0000-0000-000000000001',
        ]);

        $futureDelivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234568',
            'message_body' => 'Upcoming greeting',
            'scheduled_at' => now()->addDays(3),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => '12345',
            'source_type' => 'birthday',
            'source_id' => '00000000-0000-0000-0000-000000000002',
        ]);

        $this->artisan('sms:sync-rolling-automations', ['--days' => 1, '--force' => true])->assertSuccessful();

        $pastDelivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $pastDelivery->status);
        $this->assertStringContainsString('Expired', $pastDelivery->error_message);

        $futureDelivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $futureDelivery->status);
    }

    public function test_already_sent_birthdays_are_not_rescheduled(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
        ], 200));

        $today = now()->startOfDay();
        $date = $today->copy()->addDays(1);
        $member = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Already',
            'last_name' => 'Sent',
            'gender' => 'female',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        // Already dispatched (simulates a previous sync run)
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Previous sync',
            'scheduled_at' => $date->copy()->hour(7),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => 'existing-job',
            'source_type' => 'birthday',
            'source_id' => $member->id,
        ]);

        Http::fake(); // Reset to track new calls

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])->assertSuccessful();

        $this->assertSame(1, ScheduledSmsDelivery::count());
        Http::assertNothingSent();
    }

    // ─── Service Reminder Pre-Scheduling ───────────────────────

    public function test_service_reminders_are_pre_scheduled_to_mnotify(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
        ], 200));

        $sundayService = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true]
        );

        ServiceReminderSettings::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $sundayService->id,
            'template' => 'Hello {first_name}! {service_name} tomorrow at {service_time}.',
            'send_day_of_week' => 6,
            'send_hour' => 20,
            'service_hour' => 9,
            'service_minute' => 0,
            'is_active' => true,
        ]);

        for ($i = 0; $i < 3; $i++) {
            Member::create([
                'branch_id' => $this->branch->id,
                'first_name' => "Member{$i}",
                'last_name' => 'Test',
                'gender' => 'male',
                'status' => 'active',
                'phone' => '024123456'.($i + 1),
            ]);
        }

        $this->artisan('sms:sync-rolling-automations', ['--days' => 14, '--force' => true])->assertSuccessful();

        $reminders = ScheduledSmsDelivery::where('source_type', 'reminder')->get();
        $this->assertGreaterThan(0, $reminders->count());

        $reminders->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
            $this->assertNotNull($delivery->mnotify_job_id);
            $this->assertSame($this->branch->id, $delivery->branch_id);
            $this->assertSame('reminder', $delivery->source_type);
            $this->assertTrue($delivery->scheduled_at->isFuture());
        });

        $saturdays = 0;
        $today = now()->startOfDay();
        for ($i = 0; $i < 14; $i++) {
            $date = $today->copy()->addDays($i);
            if ($date->dayOfWeek === Carbon::SATURDAY && $date->copy()->hour(20)->isFuture()) {
                $saturdays++;
            }
        }
        $this->assertSame(3 * $saturdays, $reminders->count());
    }

    // ─── Offline Resilience Verification ───────────────────────

    public function test_jobs_remain_on_mnotify_after_local_shutdown(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
        ], 200));

        $today = now()->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->addDays($i);
            Member::create([
                'branch_id' => $this->branch->id,
                'first_name' => "Offline{$i}",
                'last_name' => 'Test',
                'gender' => 'male',
                'status' => 'active',
                'phone' => '024123456'.($i + 1),
                'date_of_birth' => $date->format('Y-m-d'),
            ]);
        }

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])->assertSuccessful();

        $this->assertSame(7, ScheduledSmsDelivery::count());
        $this->assertSame(0, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_PENDING_API)->count());
        $this->assertSame(7, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)->count());
        $this->assertSame(7, ScheduledSmsDelivery::whereNotNull('mnotify_job_id')->count());
    }

    // ─── Edge Cases ────────────────────────────────────────────

    public function test_disabled_birthday_config_skips_birthday_sync(): void
    {
        config(['church.birthday.enabled' => false]);

        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
        ], 200));

        $today = now()->startOfDay();
        $date = $today->copy()->addDays(1);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'No',
            'last_name' => 'Birthday',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])->assertSuccessful();

        $this->assertSame(0, ScheduledSmsDelivery::count());
    }

    public function test_members_without_phone_are_excluded(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
        ], 200));

        $today = now()->startOfDay();
        $date = $today->copy()->addDays(1);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'No',
            'last_name' => 'Phone',
            'gender' => 'female',
            'status' => 'active',
            'phone' => null,
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])->assertSuccessful();

        $this->assertSame(0, ScheduledSmsDelivery::count());
    }

    public function test_inactive_members_are_excluded(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
        ], 200));

        $today = now()->startOfDay();
        $date = $today->copy()->addDays(1);

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Inactive',
            'last_name' => 'Member',
            'gender' => 'male',
            'status' => 'inactive',
            'phone' => '0241234567',
            'date_of_birth' => $date->format('Y-m-d'),
        ]);

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])->assertSuccessful();

        $this->assertSame(0, ScheduledSmsDelivery::count());
    }

    public function test_skips_when_mnotify_api_key_is_missing(): void
    {
        config(['services.mnotify.api_key' => null]);

        $this->artisan('sms:sync-rolling-automations', ['--force' => true])
            ->expectsOutputToContain('API key not configured')
            ->assertExitCode(1);
    }

    public function test_command_skips_in_testing_without_force(): void
    {
        Http::fake();

        // Simulate a dry-run deployment: without MNOTIFY_DRY_RUN=false the
        // command must refuse to run under testing even with an API key set.
        config(['services.mnotify.dry_run' => true]);

        $this->artisan('sms:sync-rolling-automations')
            ->expectsOutputToContain('Skipping')
            ->assertSuccessful();

        $this->assertSame(0, ScheduledSmsDelivery::count());
        Http::assertNothingSent();
    }
}
