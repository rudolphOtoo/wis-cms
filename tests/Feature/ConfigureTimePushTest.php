<?php

namespace Tests\Feature;

use App\Models\BirthdayMessageSettings;
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
 * Configure-time push: the moment an SMS automation is created or edited
 * in the admin panel, the upcoming scheduled messages are pushed to
 * mNotify immediately (is_schedule=true) — no waiting for the 05:00
 * sms:sync-rolling-automations batch. Edits cancel the old mNotify jobs
 * and recreate the window from the new config.
 */
class ConfigureTimePushTest extends TestCase
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
            'services.mnotify.schedule_days' => 7,
            'church.birthday.enabled' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-17 06:00:00'));

        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function fakeMnotify(bool $withCancel = false): void
    {
        $patterns = [
            '*/sms/quick*' => Http::response([
                'status' => 'success',
                'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))],
            ], 200),
            '*/balance*' => Http::response(['status' => 'success', 'summary' => ['balance' => 500]], 200),
        ];

        if ($withCancel) {
            $patterns['*/scheduled?*'] = Http::response(['status' => 'success', 'summary' => []], 200);
            $patterns['*/scheduled/*'] = Http::response(['status' => 'success'], 200);
        }

        $patterns['*'] = Http::response(['status' => 'success', 'summary' => []], 200);

        Http::fake($patterns);
    }

    protected function makeReminderSettings(array $attrs = []): ServiceReminderSettings
    {
        $type = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true]
        );

        return ServiceReminderSettings::create(array_merge([
            'branch_id' => $this->branch->id,
            'service_type_id' => $type->id,
            'template' => 'Hello {first_name}! {service_name} tomorrow at {service_time}.',
            'send_day_of_week' => 0,   // Sunday
            'send_hour' => 9,
            'service_hour' => 9,
            'service_minute' => 0,
            'is_active' => true,
        ], $attrs));
    }

    protected function makeMember(array $attrs = []): Member
    {
        static $counter = 0;
        $counter++;

        return Member::create(array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Member'.$counter,
            'last_name' => 'Test',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '024123456'.$counter,
        ], $attrs));
    }

    public function test_saving_active_reminder_settings_pushes_to_mnotify_immediately(): void
    {
        $this->fakeMnotify();

        // Members exist BEFORE the settings are saved — the configure-time
        // resync must find them and push without the daily cron.
        $this->makeMember();
        $this->makeMember();

        $this->makeReminderSettings();

        $deliveries = ScheduledSmsDelivery::where('source_type', 'reminder')->get();

        // Sunday 2026-08-23 is the only Sunday inside the 7-day window.
        $this->assertSame(2, $deliveries->count());
        $deliveries->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame('2026-08-23', $delivery->scheduled_at->toDateString());
            $this->assertSame('09:00', $delivery->scheduled_at->format('H:i'));
            $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
            $this->assertNotNull($delivery->mnotify_job_id);
        });

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sms/quick')
            && $request['is_schedule'] === true
            && $request['recipient'] !== []);
    }

    public function test_editing_reminder_settings_cancels_old_and_recreates_on_mnotify(): void
    {
        $this->fakeMnotify();
        $this->makeMember();
        $this->makeMember();

        $settings = $this->makeReminderSettings();

        $old = ScheduledSmsDelivery::where('source_type', 'reminder')->get();
        $this->assertSame(2, $old->count());
        $old->each(fn ($d) => $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $d->status));

        $this->fakeMnotify(withCancel: true);

        // Admin moves the reminder from Sunday 9 AM to Saturday 10 AM and
        // rewrites the template.
        $settings->update([
            'send_day_of_week' => 6,
            'send_hour' => 10,
            'template' => 'NEW {first_name} — see you at {service_name}, {service_time}!',
        ]);

        $deliveries = ScheduledSmsDelivery::where('source_type', 'reminder')->get();

        // Old 2 cancelled on mNotify, fresh 2 created at the new slot.
        $this->assertSame(4, $deliveries->count());

        $old->each(function (ScheduledSmsDelivery $d) {
            $d->refresh();
            $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $d->status);
        });

        $new = $deliveries->filter(fn ($d) => $d->scheduled_at->toDateString() === '2026-08-22');
        $this->assertSame(2, $new->count());
        $new->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame('10:00', $delivery->scheduled_at->format('H:i'));
            $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
            $this->assertStringStartsWith('NEW ', $delivery->message_body);
        });

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/scheduled/'));
    }

    public function test_deactivating_reminder_settings_cancels_scheduled_messages(): void
    {
        $this->fakeMnotify();
        $this->makeMember();
        $this->makeMember();

        $settings = $this->makeReminderSettings();
        $this->assertSame(2, ScheduledSmsDelivery::where('source_type', 'reminder')->count());

        $this->fakeMnotify(withCancel: true);
        $settings->update(['is_active' => false]);

        ScheduledSmsDelivery::where('source_type', 'reminder')->get()->each(function ($d) {
            $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $d->status);
        });
    }

    public function test_saving_active_birthday_settings_pushes_to_mnotify_immediately(): void
    {
        $this->fakeMnotify();

        $settings = BirthdayMessageSettings::create([
            'branch_id' => $this->branch->id,
            'template' => 'Happy birthday {first_name}! {church_name} loves you.',
            'is_active' => true,
        ]);

        $this->makeMember(['date_of_birth' => '1990-08-20', 'first_name' => 'Ama']);
        $this->makeMember(['date_of_birth' => '1988-08-20', 'first_name' => 'Kofi']);

        // The admin save (template change) is what triggers the push —
        // not the auto-created row, which must NEVER schedule by itself.
        $settings->update(['template' => 'Wishing {first_name} a joyful day! — {church_name}']);

        $deliveries = ScheduledSmsDelivery::where('source_type', 'birthday')->get();

        $this->assertSame(2, $deliveries->count());
        $deliveries->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame('2026-08-20', $delivery->scheduled_at->toDateString());
            $this->assertSame('07:00', $delivery->scheduled_at->format('H:i'));
            $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
            $this->assertStringStartsWith('Wishing ', $delivery->message_body);
        });

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sms/quick')
            && $request['is_schedule'] === true);
    }

    public function test_deactivating_birthday_settings_cancels_scheduled_messages(): void
    {
        $this->fakeMnotify();

        $settings = BirthdayMessageSettings::create([
            'branch_id' => $this->branch->id,
            'template' => 'Happy birthday {first_name}!',
            'is_active' => true,
        ]);

        $this->makeMember(['date_of_birth' => '1990-08-20']);
        $this->makeMember(['date_of_birth' => '1988-08-20']);

        $settings->update(['template' => 'Wishing {first_name} a joyful day!']);
        $this->assertSame(2, ScheduledSmsDelivery::where('source_type', 'birthday')->count());

        $this->fakeMnotify(withCancel: true);
        $settings->update(['is_active' => false]);

        ScheduledSmsDelivery::where('source_type', 'birthday')->get()->each(function ($d) {
            $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $d->status);
        });

        $this->assertSame(2, ScheduledSmsDelivery::count());
    }

    public function test_daily_sync_after_configure_time_push_does_not_duplicate(): void
    {
        $this->fakeMnotify();
        $this->makeMember();
        $this->makeMember();

        $this->makeReminderSettings();
        $this->assertSame(2, ScheduledSmsDelivery::where('source_type', 'reminder')->count());

        $this->artisan('sms:sync-rolling-automations', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        // Still exactly the configure-time deliveries — no duplicates.
        $this->assertSame(2, ScheduledSmsDelivery::where('source_type', 'reminder')->count());
        $this->assertSame(0, ScheduledSmsDelivery::where('source_type', 'birthday')->count());
    }

    public function test_inactive_reminder_settings_do_not_push_on_save(): void
    {
        $this->fakeMnotify();
        $this->makeMember();
        $this->makeMember();

        $this->makeReminderSettings(['is_active' => false]);

        $this->assertSame(0, ScheduledSmsDelivery::count());
        Http::assertNothingSent();
    }
}
