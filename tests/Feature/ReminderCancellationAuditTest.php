<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderLog;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The audit trail left behind when a reminder message is withdrawn.
 *
 * Cancelling removes the dispatch from the scheduled list on purpose, so
 * the log is the only place that can answer "was this withdrawn, by whom,
 * and did mNotify actually let go of it?". Two properties are asserted
 * throughout:
 *
 *   1. every deliberate cancellation leaves a record, and
 *   2. nothing else does — the resync churn that retires a hundred
 *      dispatches every time a template is edited is bookkeeping, and
 *      logging it as an admin action would bury the real entries.
 */
class ReminderCancellationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected ServiceType $serviceType;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);

        // Saturday 10 Oct 2026 — the reminder send slot, a day before the
        // Sunday service it is for.
        Carbon::setTestNow(Carbon::parse('2026-09-27 07:31:00'));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Pastor Kwame',
            'email' => 'pastor@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->serviceType = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true]
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function member(string $phone = '0540765021', string $first = 'Edith'): Member
    {
        return Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => $first,
            'last_name' => 'Owusu',
            'gender' => 'female',
            'status' => 'active',
            'phone' => $phone,
        ]);
    }

    protected function settings(bool $active = true): ServiceReminderSettings
    {
        return ServiceReminderSettings::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $this->serviceType->id,
            'template' => 'Dear {first_name}, Sunday service at {service_time}.',
            'send_day_of_week' => 6,   // Saturday
            'send_hour' => 12,
            'service_hour' => 7,
            'service_minute' => 0,
            'is_active' => $active,
        ]);
    }

    protected function delivery(
        string $status = ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
        ?string $jobId = 'push-ref-abc',
        ?string $sourceId = null,
        string $phone = '0540765021',
    ): ScheduledSmsDelivery {
        return ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $phone,
            'message_body' => 'Dear Edith, Sunday Divine Service comes off at 7:00 AM.',
            'scheduled_at' => Carbon::parse('2026-10-10 12:00:00'),
            'status' => $status,
            'source_type' => 'reminder',
            'source_id' => $sourceId,
            'mnotify_job_id' => $jobId,
        ]);
    }

    /**
     * @param  list<string>  $deleted
     */
    protected function fakeProvider(array &$deleted, int $deleteStatus = 200): void
    {
        Http::fake(function (Request $request) use (&$deleted, $deleteStatus) {
            $url = $request->url();

            if (preg_match('#/scheduled/([^/?]+)#', $url, $m)) {
                $deleted[] = $m[1];

                return Http::response(['status' => 'success'], $deleteStatus);
            }

            if (str_contains($url, '/scheduled?') || str_ends_with($url, '/scheduled')) {
                return Http::response(['status' => 'success', 'summary' => [
                    ['_id' => '213781', 'date_time' => '2026-10-10 12:00:00', 'message' => 'Dear Edith, Sunday Divine Service comes off at 7:00 AM.'],
                ]], 200);
            }

            return Http::response(['status' => 'success'], 200);
        });
    }

    public function test_cancelling_a_message_records_who_took_it_and_when(): void
    {
        $member = $this->member();
        $settings = $this->settings();
        $delivery = $this->delivery(sourceId: $settings->id);

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertOk()
            ->assertJsonPath('cloud_cancelled', true);

        $log = ServiceReminderLog::query()->sole();

        $this->assertSame(ServiceReminderLog::STATUS_CANCELLED, $log->status);
        $this->assertSame($this->user->id, $log->cancelled_by);
        $this->assertSame($member->id, $log->member_id);
        $this->assertSame('0540765021', $log->phone_used);
        $this->assertSame($settings->service_type_id, $log->service_type_id);
        $this->assertStringContainsString('Withdrawn from mNotify', $log->detail);

        // Filed under the service date, not the Saturday send slot.
        $this->assertSame('2026-10-11', $log->intended_service_date->toDateString());
        $this->assertSame('2026-09-27 07:31:00', $log->sent_at->toDateTimeString());
    }

    public function test_cancellation_is_not_recorded_as_a_send(): void
    {
        $this->settings();
        $delivery = $this->delivery();

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertOk();

        // A withdrawn message was never delivered, and the idempotency
        // check keys off STATUS_SENT: filing it as a send would tell the
        // next run that this member already got the reminder.
        $this->assertSame(0, ServiceReminderLog::status(ServiceReminderLog::STATUS_SENT)->count());
    }

    public function test_a_message_never_uploaded_says_so_in_the_log(): void
    {
        $this->settings();
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_PENDING_API, null);

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertOk()
            ->assertJsonPath('cloud_cancelled', false);

        $this->assertStringContainsString(
            'never reached mNotify',
            ServiceReminderLog::query()->sole()->detail
        );
    }

    public function test_an_unconfirmed_withdrawal_is_logged_as_possibly_still_active(): void
    {
        $this->settings();
        $delivery = $this->delivery();

        $deleted = [];
        $this->fakeProvider($deleted, deleteStatus: 500);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertStatus(202);

        // The action was taken and the log has to say so — but the
        // message may still reach the member, and an entry claiming a
        // clean cancel would be the one thing an admin relies on.
        $this->assertStringContainsString(
            'possibly still active',
            ServiceReminderLog::query()->sole()->detail
        );
    }

    public function test_the_withdrawal_shows_up_in_the_admin_log_endpoint(): void
    {
        $this->member();
        $settings = $this->settings();
        $delivery = $this->delivery(sourceId: $settings->id);

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertOk();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reminders/log?days=30&status=cancelled,cancelled_batch');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', ServiceReminderLog::STATUS_CANCELLED)
            ->assertJsonPath('data.0.status_label', 'Cancelled')
            ->assertJsonPath('data.0.headline', 'Edith Owusu')
            ->assertJsonPath('data.0.cancelled_by', 'Pastor Kwame')
            ->assertJsonPath('data.0.intended_service_date', '2026-10-11');
    }

    public function test_a_whole_reminder_being_cancelled_is_one_entry_not_one_per_member(): void
    {
        $settings = $this->settings();

        for ($i = 0; $i < 5; $i++) {
            $this->delivery(sourceId: $settings->id, phone: '054076502'.$i);
        }

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/reminders/settings/{$settings->service_type_id}", [
                'template' => $settings->template,
                'send_day_of_week' => $settings->send_day_of_week,
                'send_hour' => $settings->send_hour,
                'service_hour' => $settings->service_hour,
                'service_minute' => $settings->service_minute,
                'is_active' => false,
            ])
            ->assertOk();

        $log = ServiceReminderLog::query()->sole();

        $this->assertSame(ServiceReminderLog::STATUS_CANCELLED_BATCH, $log->status);
        $this->assertSame($this->user->id, $log->cancelled_by);
        $this->assertSame($settings->service_type_id, $log->service_type_id);

        // Belongs to the reminder, not to a member or a single date.
        $this->assertNull($log->member_id);
        $this->assertNull($log->intended_service_date);
        $this->assertSame('Reminder switched off — 5 future messages withdrawn from mNotify.', $log->detail);
    }

    public function test_the_cancelled_list_names_the_automation_not_a_member(): void
    {
        $settings = $this->settings();
        $this->delivery(sourceId: $settings->id);

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/reminders/settings/{$settings->service_type_id}", [
                'template' => $settings->template,
                'send_day_of_week' => $settings->send_day_of_week,
                'send_hour' => $settings->send_hour,
                'service_hour' => $settings->service_hour,
                'service_minute' => $settings->service_minute,
                'is_active' => false,
            ])
            ->assertOk();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reminders/log?days=30&status=cancelled,cancelled_batch')
            ->assertOk()
            ->assertJsonPath('data.0.status_label', 'Reminder cancelled')
            ->assertJsonPath('data.0.headline', 'All Sunday Adult Service reminders');
    }

    public function test_reconfiguring_an_active_reminder_is_not_logged_as_a_cancellation(): void
    {
        $settings = $this->settings();
        $this->delivery(sourceId: $settings->id);

        $deleted = [];
        $this->fakeProvider($deleted);

        // Editing the template while active resyncs the whole forward
        // window: every future dispatch is retired and recreated. That is
        // bookkeeping, and it happens on every save — logging it as an
        // admin cancellation would fill the log with phantom actions.
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/reminders/settings/{$settings->service_type_id}", [
                'template' => 'Dear {first_name}, worship with us at {service_time}.',
                'send_day_of_week' => $settings->send_day_of_week,
                'send_hour' => $settings->send_hour,
                'service_hour' => $settings->service_hour,
                'service_minute' => $settings->service_minute,
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertSame(0, ServiceReminderLog::count());
    }

    public function test_a_rejected_cancel_is_not_logged(): void
    {
        $this->settings();
        // Already sent: nothing to withdraw, so nothing to record.
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_DISPATCHED, 'push-ref-abc');

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertStatus(409);

        $this->assertSame(0, ServiceReminderLog::count());
    }

    public function test_the_withdrawal_survives_the_member_being_deleted(): void
    {
        $member = $this->member();
        $settings = $this->settings();
        $delivery = $this->delivery(sourceId: $settings->id);

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertOk();

        // Hard delete, so the foreign key actually fires. An audit entry
        // that vanished with the person it is about would be worthless:
        // the member link is dropped instead, and the number that was
        // texted keeps the entry readable.
        $member->forceDelete();

        $log = ServiceReminderLog::query()->sole();
        $this->assertNull($log->member_id);
        $this->assertSame('0540765021', $log->phone_used);
        $this->assertSame($this->user->id, $log->cancelled_by);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reminders/log?days=30&status=cancelled')
            ->assertOk()
            ->assertJsonPath('data.0.headline', '0540765021');
    }

    public function test_unknown_log_status_filter_is_rejected(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reminders/log?status=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }
}
