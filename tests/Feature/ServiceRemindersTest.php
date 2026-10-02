<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderLog;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use App\Services\MnotifySmsService;
use App\Services\RecurringSmsScheduler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for the SendServiceReminders command. Mirrors the
 * BirthdayGreetingsTest pattern with service-reminder specifics:
 *   - Settings rows that target a specific (DOW, hour)
 *   - Idempotency via (member, service_type, intended_service_date)
 *   - intended_service_date computed from service type's natural DOW
 *   - One reminder per member per day even when two active settings rows
 *     share a dispatch slot, and even when the command runs twice
 */
class ServiceRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected ServiceType $sundayService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();

        // Use the migration-seeded service type if present, else create.
        $this->sundayService = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            [
                'branch_id' => $this->branch->id,
                'name' => 'Sunday Adult Service',
                'type' => 'adult',
                'is_active' => true,
            ]
        );
    }

    protected function makeMember(array $attrs = []): Member
    {
        static $counter = 0;
        $counter++;

        return Member::create(array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'gender' => 'female',
            'status' => 'active',
            'phone' => '024123456'.$counter,
        ], $attrs));
    }

    protected function makeSettings(array $attrs = []): ServiceReminderSettings
    {
        return ServiceReminderSettings::create(array_merge([
            'branch_id' => $this->branch->id,
            'service_type_id' => $this->sundayService->id,
            'template' => 'Hello {first_name}! {service_name} tomorrow at {service_time}.',
            'send_day_of_week' => 6,   // Saturday
            'send_hour' => 20,          // 8 PM
            'service_hour' => 9,
            'service_minute' => 0,
            'is_active' => true,
        ], $attrs));
    }

    protected function mockSms(): void
    {
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->andReturn(true));
    }

    // 2026-06-13 is a Saturday at 8 PM
    protected string $saturday8pm = '2026-06-13 20:00:00';

    public function test_no_settings_means_no_send(): void
    {
        $this->makeMember(['date_of_birth' => '1990-05-28']);

        $this->artisan('reminders:send', ['--at' => $this->saturday8pm, '--force' => true])
            ->expectsOutputToContain('No reminders configured')
            ->assertSuccessful();

        $this->assertSame(0, ServiceReminderLog::count());
        $this->assertSame(0, Message::count());
    }

    public function test_matching_settings_dispatches_to_all_active_members_with_phones(): void
    {
        $this->mockSms();

        $this->makeSettings();

        // 3 active members with phones
        $m1 = $this->makeMember(['first_name' => 'Kofi']);
        $m2 = $this->makeMember(['first_name' => 'Adwoa']);
        $m3 = $this->makeMember(['first_name' => 'Yaw']);

        // 1 active member WITHOUT phone — should be logged as no_phone
        $noPhone = $this->makeMember(['first_name' => 'Esi', 'phone' => null]);

        // 1 inactive member — should be ignored entirely
        $this->makeMember(['first_name' => 'Kwame', 'status' => 'inactive']);

        $this->artisan('reminders:send', ['--at' => $this->saturday8pm, '--force' => true])
            ->expectsOutputToContain('3 sent')
            ->assertSuccessful();

        // 4 logs (3 sent + 1 no_phone)
        $this->assertSame(4, ServiceReminderLog::count());
        $this->assertSame(3, ServiceReminderLog::status('sent')->count());
        $this->assertSame(1, ServiceReminderLog::status('no_phone')->count());

        // The inactive member must NOT have a log
        $this->assertSame(0, ServiceReminderLog::where('member_id', $this->branch->id)->count());

        // Each Message dispatched is recipient_group='service_reminder'
        $this->assertSame(3, Message::where('recipient_group', 'service_reminder')->count());
    }

    public function test_idempotent_within_same_hour(): void
    {
        $this->mockSms();
        $this->makeSettings();
        $this->makeMember();

        // First run: dispatches
        $this->artisan('reminders:send', ['--at' => $this->saturday8pm, '--force' => true])->assertSuccessful();
        $this->assertSame(1, ServiceReminderLog::status('sent')->count());

        // Second run at the same moment: skips (idempotent)
        $this->artisan('reminders:send', ['--at' => $this->saturday8pm, '--force' => true])
            ->expectsOutputToContain('1 idempotent-skip')
            ->assertSuccessful();

        // Still only 1 sent log
        $this->assertSame(1, ServiceReminderLog::status('sent')->count());
    }

    public function test_inactive_settings_row_is_ignored(): void
    {
        $this->makeSettings(['is_active' => false]);
        $this->makeMember();

        $this->artisan('reminders:send', ['--at' => $this->saturday8pm, '--force' => true])
            ->expectsOutputToContain('No reminders configured')
            ->assertSuccessful();

        $this->assertSame(0, ServiceReminderLog::count());
    }

    public function test_intended_service_date_for_sunday_is_next_sunday(): void
    {
        $this->mockSms();
        $this->makeSettings();   // Saturday 8 PM → for Sunday service
        $this->makeMember();

        // Fires Saturday 13 Jun 2026 8 PM → intended service is Sunday 14 Jun 2026
        $this->artisan('reminders:send', ['--at' => $this->saturday8pm, '--force' => true])->assertSuccessful();

        $log = ServiceReminderLog::first();
        $this->assertSame('2026-06-14', $log->intended_service_date->toDateString());
    }

    public function test_no_match_when_hour_doesnt_align(): void
    {
        $this->mockSms();
        $this->makeSettings();   // configured for hour 20
        $this->makeMember();

        // Same day, but 7 PM instead of 8 PM
        $this->artisan('reminders:send', ['--at' => '2026-06-13 19:00:00', '--force' => true])
            ->expectsOutputToContain('No reminders configured')
            ->assertSuccessful();

        $this->assertSame(0, ServiceReminderLog::count());
    }

    // ─── Duplicate settings in one dispatch slot ─────────────────
    //
    // The reported defect: a member received two or three differently
    // worded reminders for the same service at the same minute. The unique
    // (branch, service_type) index rules out two rows for ONE service
    // type, so the colliding rows are always different service types
    // pointed at the same (branch, weekday, hour) — each individually
    // valid, together fatal.

    /**
     * 2026-06-12 is a Friday at 12:00 — a reminder for a 6:30 PM service.
     */
    protected string $fridayNoon = '2026-06-12 12:00:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function fridaySettings(): ServiceReminderSettings
    {
        return $this->makeSettings([
            'send_day_of_week' => 5,   // Friday
            'send_hour' => 12,
            'service_hour' => 18,
            'service_minute' => 30,
            'template' => 'Dear {first_name}, you are reminded of our {service_name} today at {service_time}.',
        ]);
    }

    /**
     * A second active row in the SAME dispatch slot, pointing at a
     * different service type and worded differently — the exact pairing
     * that produced two texts at noon.
     */
    protected function competingFridaySettings(): ServiceReminderSettings
    {
        $bibleStudy = ServiceType::firstOrCreate(
            ['slug' => 'bible_study'],
            ['name' => 'Bible Study', 'type' => 'adult', 'is_active' => true]
        );

        return ServiceReminderSettings::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $bibleStudy->id,
            'template' => "Dear {first_name}, you're reminded of our {service_name} tonight at {service_time}.",
            'send_day_of_week' => 5,
            'send_hour' => 12,
            'service_hour' => 18,
            'service_minute' => 30,
            'is_active' => true,
        ]);
    }

    public function test_competing_active_settings_in_same_slot_dispatches_only_one_template(): void
    {
        $this->mockSms();

        $winner = $this->fridaySettings();
        $loser = $this->competingFridaySettings();

        $this->assertSame(
            $winner->branch_id,
            $loser->branch_id,
            'Both rows must belong to the same branch for this to be the reported defect.'
        );
        $this->assertNotSame(
            $winner->service_type_id,
            $loser->service_type_id,
            'The unique (branch, service_type) index means a contested slot always spans two service types.'
        );

        $m1 = $this->makeMember(['first_name' => 'Rudolph', 'phone' => '0241110001']);
        $m2 = $this->makeMember(['first_name' => 'Adwoa', 'phone' => '0241110002']);

        $this->artisan('reminders:send', ['--at' => $this->fridayNoon, '--force' => true])
            ->assertSuccessful();

        // Exactly one reminder per member — not one per settings row.
        $this->assertSame(
            2,
            ServiceReminderLog::status('sent')->count(),
            'Two active settings rows in one slot must still produce exactly one reminder per member.'
        );
        $this->assertSame(2, Message::where('recipient_group', 'service_reminder')->count());

        foreach ([$m1, $m2] as $member) {
            $this->assertSame(
                1,
                ServiceReminderLog::where('member_id', $member->id)
                    ->where('status', ServiceReminderLog::STATUS_SENT)
                    ->count(),
                "Member {$member->phone} received more than one reminder for the same service day."
            );
        }
    }

    public function test_the_newest_settings_row_wins_a_contested_slot(): void
    {
        $this->fridaySettings();
        $newest = $this->competingFridaySettings();

        $resolved = ServiceReminderSettings::query()
            ->where('is_active', true)
            ->where('send_day_of_week', 5)
            ->where('send_hour', 12)
            ->withoutSlotDuplicates()
            ->pluck('id');

        $this->assertSame(
            [$newest->id],
            $resolved->all(),
            'The most recently configured row is the authoritative one for the slot; the other is suppressed.'
        );
    }

    public function test_slot_dedup_never_suppresses_an_active_row_behind_an_inactive_one(): void
    {
        $active = $this->fridaySettings();

        // Created AFTER the active row, so it is the newest in the slot —
        // but switched OFF. It must not win, and must not drag the live
        // row down with it; otherwise switching a reminder off would
        // silence the church entirely.
        $newest = $this->competingFridaySettings();
        $newest->update(['is_active' => false]);

        $resolved = ServiceReminderSettings::query()
            ->where('is_active', true)
            ->where('send_day_of_week', 5)
            ->where('send_hour', 12)
            ->withoutSlotDuplicates()
            ->pluck('id');

        $this->assertSame([$active->id], $resolved->all());
    }

    public function test_different_slots_are_both_kept(): void
    {
        $fridayNoon = $this->fridaySettings();

        $children = ServiceType::firstOrCreate(
            ['slug' => 'sunday_children'],
            ['name' => 'Sunday Children Service', 'type' => 'children', 'is_active' => true]
        );

        // Same branch, different service type, different day AND hour.
        // Legitimately a separate reminder and must survive the dedupe.
        $saturdayEvening = ServiceReminderSettings::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $children->id,
            'template' => 'Reminder for tomorrow.',
            'send_day_of_week' => 6,
            'send_hour' => 20,
            'service_hour' => 9,
            'service_minute' => 0,
            'is_active' => true,
        ]);

        $resolved = ServiceReminderSettings::query()
            ->where('is_active', true)
            ->withoutSlotDuplicates()
            ->pluck('id');

        $this->assertEqualsCanonicalizing(
            [$fridayNoon->id, $saturdayEvening->id],
            $resolved->all()
        );
    }

    public function test_rolling_sync_queues_one_delivery_per_member_with_two_settings_in_the_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-12 08:00:00'));

        $this->fridaySettings();
        $this->competingFridaySettings();

        $this->makeMember(['first_name' => 'Rudolph', 'phone' => '0241110001']);
        $this->makeMember(['first_name' => 'Adwoa', 'phone' => '0241110002']);

        $scheduler = app(RecurringSmsScheduler::class);

        // Today (Friday) AND the next Friday are both inside the window.
        $scheduler->collectServiceReminderDeliveries(8);

        $this->assertSame(
            4,
            ScheduledSmsDelivery::count(),
            'Expected 2 members x 2 Fridays of deliveries, not double for each settings row.'
        );

        foreach (ScheduledSmsDelivery::all() as $delivery) {
            $sameSlot = ScheduledSmsDelivery::where('phone', $delivery->phone)
                ->whereDate('scheduled_at', $delivery->scheduled_at)
                ->count();

            $this->assertSame(
                1,
                $sameSlot,
                "Phone {$delivery->phone} has {$sameSlot} reminders queued for the same day."
            );
        }
    }

    public function test_rolling_sync_is_idempotent_when_run_twice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-12 08:00:00'));

        $this->fridaySettings();
        $this->makeMember(['phone' => '0241110001']);

        $scheduler = app(RecurringSmsScheduler::class);

        $scheduler->collectServiceReminderDeliveries(8);
        $firstCount = ScheduledSmsDelivery::count();

        $scheduler->collectServiceReminderDeliveries(8);

        $this->assertSame(
            $firstCount,
            ScheduledSmsDelivery::count(),
            'Re-running the collector must not add a second delivery for a slot it already filled.'
        );
    }

    // ─── Recipient + date idempotency across dispatch paths ──────

    public function test_a_reminder_already_queued_by_another_automation_blocks_the_local_send(): void
    {
        $this->mockSms();

        $member = $this->makeMember(['phone' => '0241110001']);
        $settings = $this->fridaySettings();

        // The cloud holds this member's reminder — but under a DIFFERENT
        // settings row's source_id. Matching on source_id would let the
        // local sender fire on top of it.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'reminder reserved on the cloud',
            'scheduled_at' => Carbon::parse($this->fridayNoon),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
            'source_id' => ServiceReminderSettings::whereKey($settings->id)->value('id'),
            'mnotify_job_id' => 'cloud-job-xyz',
        ]);

        $this->artisan('reminders:send', ['--at' => $this->fridayNoon, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(
            0,
            Message::where('recipient_group', 'service_reminder')->count(),
            'The member already has this reminder on the cloud; sending again would double-text them.'
        );
    }

    public function test_a_withdrawn_reminder_does_not_block_the_local_send(): void
    {
        $this->mockSms();

        $member = $this->makeMember(['phone' => '0241110001']);
        $settings = $this->fridaySettings();

        // Cancelled on the cloud — the member was never reached, so the
        // local fallback is the last chance to text them.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'withdrawn',
            'scheduled_at' => Carbon::parse($this->fridayNoon),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
            'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
            'source_id' => $settings->id,
            'mnotify_job_id' => 'cloud-job-dead',
        ]);

        $this->artisan('reminders:send', ['--at' => $this->fridayNoon, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, Message::where('recipient_group', 'service_reminder')->count());
    }

    public function test_repeated_dispatch_on_same_day_skips_members_already_notified(): void
    {
        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);

        Carbon::setTestNow(Carbon::parse($this->fridayNoon));

        $this->fridaySettings();
        $member = $this->makeMember(['first_name' => 'Rudolph', 'phone' => '0241110001']);

        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            if (str_contains($request->url(), '/sms/quick')) {
                $sent[] = $request['recipient'][0] ?? '?';

                return Http::response(['status' => 'success', 'summary' => ['id' => 'direct-'.count($sent)]], 200);
            }

            return Http::response(['status' => 'success', 'summary' => []], 200);
        });

        $this->artisan('reminders:send')->assertSuccessful();
        $this->assertCount(1, $sent, 'First run should deliver exactly one text.');

        $messagesAfterFirst = Message::count();
        $recipientsAfterFirst = MessageRecipient::count();

        // Second run, same hour. Nothing at all should go out.
        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertCount(
            1,
            $sent,
            'A second dispatch in the same hour must not send a second text to the same member.'
        );
        $this->assertSame($messagesAfterFirst, Message::count());
        $this->assertSame($recipientsAfterFirst, MessageRecipient::count());
        $this->assertSame(
            1,
            ServiceReminderLog::where('member_id', $member->id)
                ->where('status', ServiceReminderLog::STATUS_SENT)
                ->count()
        );
    }

    // ─── sms:dedupe-service-reminders ───────────────────────────

    public function test_dedupe_command_finds_and_withdraws_duplicate_future_reminders(): void
    {
        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-12 08:00:00'));

        $settings = $this->fridaySettings();
        $member = $this->makeMember(['phone' => '0241110001']);
        $fridayNoon = Carbon::parse('2026-06-12 12:00:00');

        // The authoritative copy, confirmed by the provider.
        $keeper = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'Dear Rudolph, prayer service today at 6:30 PM.',
            'scheduled_at' => $fridayNoon,
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
            'source_id' => $settings->id,
            'mnotify_job_id' => 'keep-me',
        ]);

        // Two surplus copies from the two-settings-row defect.
        foreach (['spare-1', 'spare-2'] as $index => $jobId) {
            ScheduledSmsDelivery::create([
                'branch_id' => $this->branch->id,
                'phone' => $member->phone,
                'message_body' => $index === 0
                    ? "Dear Rudolph, you're reminded of our prayer service tonight at 6:30 PM."
                    : 'Prayer meeting reminder.',
                'scheduled_at' => $fridayNoon->copy()->addMinutes($index),
                'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
                'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
                'source_id' => $settings->id,
                'mnotify_job_id' => $jobId,
            ]);
        }

        $deleted = [];
        Http::fake(function (Request $request) use (&$deleted) {
            $url = $request->url();

            if ($request->method() === 'DELETE') {
                $deleted[] = $url;

                return Http::response(['status' => 'success'], 200);
            }

            if (str_contains($url, '/scheduled?')) {
                return Http::response(['status' => 'success', 'summary' => []], 200);
            }

            return Http::response(['status' => 'success', 'summary' => []], 200);
        });

        // Dry run first — nothing may be cancelled.
        $this->artisan('sms:dedupe-service-reminders')
            ->expectsOutputToContain('2')
            ->assertSuccessful();

        $this->assertSame([], $deleted, 'The dry run must not call the provider.');
        $this->assertSame(3, ScheduledSmsDelivery::active()->count());

        // --execute alone must not act unattended; it needs the prompt answered.
        $this->artisan('sms:dedupe-service-reminders', ['--execute' => true])
            ->expectsConfirmation('Withdraw 2 duplicate delivery/deliveries now?', 'no')
            ->expectsOutputToContain('Aborted. Nothing was withdrawn.')
            ->assertSuccessful();

        $this->assertSame([], $deleted, 'Declining the confirmation must not call the provider.');
        $this->assertSame(3, ScheduledSmsDelivery::active()->count());

        $this->artisan('sms:dedupe-service-reminders', ['--execute' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertCount(2, $deleted, 'Both surplus copies must be withdrawn from mNotify.');
        $this->assertSame(
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            $keeper->fresh()->status,
            'The authoritative copy must survive the cleanup.'
        );
        $this->assertSame(1, ScheduledSmsDelivery::active()->count());

        // Re-running must find nothing left to do.
        $this->artisan('sms:dedupe-service-reminders', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('No duplicate service reminders found')
            ->assertSuccessful();

        $this->assertCount(2, $deleted);
    }

    /**
     * mNotify's DELETE /scheduled/{id} is answering HTTP 500 in production,
     * so the 2099 defusal is the path that actually runs. It must park the
     * job on the provider AND reconcile the local row, or the next run sees
     * a duplicate that is still live.
     */
    public function test_dedupe_command_defuses_future_duplicate_deliveries_on_mnotify(): void
    {
        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-12 08:00:00'));

        $settings = $this->fridaySettings();
        $member = $this->makeMember(['phone' => '0241110001']);
        $fridayNoon = Carbon::parse('2026-06-12 12:00:00');

        $keeper = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'Authoritative reminder.',
            'scheduled_at' => $fridayNoon,
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
            'source_id' => $settings->id,
            'mnotify_job_id' => 'keep-me',
        ]);

        $surplus = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'Duplicate reminder, differently worded.',
            'scheduled_at' => $fridayNoon->copy()->addMinutes(2),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
            'source_id' => $settings->id,
            'mnotify_job_id' => 'spare-1',
        ]);

        $deleteCalls = 0;
        $defusePayloads = [];

        Http::fake(function (Request $request) use (&$deleteCalls, &$defusePayloads) {
            $url = $request->url();

            if ($request->method() === 'DELETE' && str_contains($url, '/scheduled/')) {
                $deleteCalls++;

                // The real failure: provider refuses to cancel.
                return Http::response(['status' => 'error'], 500);
            }

            if ($request->method() === 'PUT' && str_contains($url, '/scheduled/')) {
                $defusePayloads[] = $request->data();

                return Http::response(['status' => 'success'], 200);
            }

            return Http::response(['status' => 'success', 'summary' => []], 200);
        });

        $this->artisan('sms:dedupe-service-reminders', ['--execute' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, $deleteCalls, 'DELETE must be attempted first.');

        $this->assertCount(1, $defusePayloads, 'The refused DELETE must escalate to a 2099 defusal.');
        $this->assertSame(
            '2099-12-31 07:00',
            $defusePayloads[0]['schedule_date'] ?? null,
            'The defusal must park the job beyond any plausible service date.'
        );

        // Local state reconciled, so the next run does not see it again.
        $this->assertSame(
            ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
            $surplus->fresh()->status
        );
        $this->assertSame(1, ScheduledSmsDelivery::active()->count());
        $this->assertSame(
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            $keeper->fresh()->status,
            'The authoritative copy must survive.'
        );

        $this->artisan('sms:dedupe-service-reminders', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('No duplicate service reminders found')
            ->assertSuccessful();
    }

    /**
     * When mNotify is unreachable the job defers instead of cancelling, and
     * the duplicate is still live on the provider. The operator must be told
     * that plainly, with the reason, rather than being handed a silent
     * no-op.
     */
    public function test_dedupe_command_reports_the_reason_a_duplicate_is_still_live(): void
    {
        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-12 08:00:00'));

        $settings = $this->fridaySettings();
        $member = $this->makeMember(['phone' => '0241110001']);
        $fridayNoon = Carbon::parse('2026-06-12 12:00:00');

        foreach (['a', 'b'] as $index => $jobId) {
            ScheduledSmsDelivery::create([
                'branch_id' => $this->branch->id,
                'phone' => $member->phone,
                'message_body' => 'Reminder copy '.$index,
                'scheduled_at' => $fridayNoon->copy()->addMinutes($index),
                'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
                'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
                'source_id' => $settings->id,
                'mnotify_job_id' => $jobId,
            ]);
        }

        // Everything fails, including the defusal.
        Http::fake(fn () => Http::response(['status' => 'error'], 500));

        // One expectation, because Laravel registers a competing doWrite()
        // matcher per substring and Mockery hands each write to only the
        // first one that fits — two substrings on the same line can never
        // both verify.
        $this->artisan('sms:dedupe-service-reminders', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('NOT withdrawn (still scheduled_remote): mNotify server error: HTTP 500')
            ->assertFailed();

        // The provider's own words, on the console — previously swallowed by
        // the scheduler's fire-and-observe cancel, leaving the operator with
        // a bare "still scheduled_remote" and no idea what went wrong.
        $this->assertStringContainsString(
            'HTTP 500',
            PendingRemoteSchedule::where('action', PendingRemoteSchedule::ACTION_CANCEL)->sole()->error_message
        );

        $this->assertSame(
            2,
            ScheduledSmsDelivery::active()->count(),
            'A deferred cancellation must leave the duplicates alone, not half-applied.'
        );
    }

    /**
     * The idempotency guards answer for a whole branch in one lookup each.
     * This pins that down, because the obvious way to write the guard — one
     * exists() per member — is correct and N+1 at the same time, and nothing
     * about the feature tests would notice.
     *
     * Measured on the fully-blocked run, where every member is probed: the
     * guard must cost the same 3 reads whether the branch has 5 members or
     * 50. Before this was batched the same run issued one read per member per
     * guard (3 x N).
     */
    public function test_the_reminder_guards_do_not_scale_with_branch_size(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-12 08:00:00'));

        $this->fridaySettings();

        foreach (range(1, 50) as $i) {
            $this->makeMember(['phone' => sprintf('0244000%03d', $i)]);
        }

        $scheduler = app(RecurringSmsScheduler::class);

        // First pass fills every slot; the second is the worst case, where
        // each member is evaluated and rejected.
        $scheduler->collectServiceReminderDeliveries(8);

        $count = function (callable $run): int {
            $queries = 0;

            DB::listen(function ($event) use (&$queries) {
                if (str_starts_with(strtolower(trim($event->sql)), 'select')
                    && str_contains($event->sql, 'scheduled_sms_deliveries')) {
                    $queries++;
                }
            });

            $run();

            return $queries;
        };

        $blockedReads = $count(fn () => $scheduler->collectServiceReminderDeliveries(8));

        // One read per guard (own-slot tombstones, recipient claim, orphan
        // reclaim) — not one per guard per member.
        $this->assertLessThanOrEqual(
            6,
            $blockedReads,
            "Slot guards issued {$blockedReads} reads for 50 members; the batch should need about 3."
        );

        $this->assertSame(100, ScheduledSmsDelivery::count());
    }

    /**
     * The date predicate has to stay a plain comparison. whereDate() compiles
     * to `scheduled_at::date = ?`, and that cast stops the index bounding the
     * scan by day — the reminder index silently becomes useless and the guard
     * degrades to a scan of every row the phone ever had.
     */
    public function test_the_reminder_slot_index_can_bound_the_guard_lookup_by_date(): void
    {
        // Capture what the guard actually emits, rather than rebuilding the
        // query by hand — a hand-built copy would keep passing after the
        // model regressed back to whereDate().
        $sql = '';

        DB::listen(function ($event) use (&$sql) {
            if (str_contains($event->sql, 'scheduled_sms_deliveries')
                && str_starts_with(strtolower(trim($event->sql)), 'select')) {
                $sql = $event->sql;
            }
        });

        ScheduledSmsDelivery::reminderPhonesInFlight(
            Carbon::parse('2026-06-12 12:00:00'),
            ['0241110001'],
        );

        $this->assertNotSame('', $sql, 'The guard issued no query to inspect.');
        $this->assertStringNotContainsString(
            '::date',
            $sql,
            'A cast on scheduled_at prevents the reminder slot index from being used.'
        );
        $this->assertStringContainsString('"scheduled_at" >= ?', $sql);
        $this->assertStringContainsString('"scheduled_at" < ?', $sql);

        $indexed = collect(Schema::getIndexes('scheduled_sms_deliveries'))
            ->firstWhere('name', 'idx_sms_reminder_slot');

        $this->assertNotNull($indexed, 'The reminder slot index is missing.');
        $this->assertSame(
            ['source_type', 'phone', 'scheduled_at', 'status'],
            $indexed['columns']
        );
    }

    public function test_dedupe_command_leaves_past_and_distinct_recipients_alone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-12 08:00:00'));

        $settings = $this->fridaySettings();

        // Two texts for the same person, but for a service day already
        // past — a sent message cannot be recalled.
        foreach (['past-a', 'past-b'] as $jobId) {
            ScheduledSmsDelivery::create([
                'branch_id' => $this->branch->id,
                'phone' => '0241110999',
                'message_body' => 'already sent',
                'scheduled_at' => Carbon::parse('2026-06-05 12:00:00'),
                'status' => ScheduledSmsDelivery::STATUS_DISPATCHED,
                'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
                'source_id' => $settings->id,
                'mnotify_job_id' => $jobId,
            ]);
        }

        // Same day, different people: not duplicates.
        foreach (['0241110001', '0241110002'] as $phone) {
            ScheduledSmsDelivery::create([
                'branch_id' => $this->branch->id,
                'phone' => $phone,
                'message_body' => 'reminder',
                'scheduled_at' => Carbon::parse('2026-06-12 12:00:00'),
                'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
                'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
                'source_id' => $settings->id,
                'mnotify_job_id' => 'job-'.$phone,
            ]);
        }

        $this->artisan('sms:dedupe-service-reminders', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('No duplicate service reminders found')
            ->assertSuccessful();

        $this->assertSame(4, ScheduledSmsDelivery::count());
        $this->assertSame(2, ScheduledSmsDelivery::scheduledRemote()->count());
        $this->assertSame(2, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_DISPATCHED)->count());
    }

    /**
     * The duplicate-skip must be loud enough to find in the log after the
     * fact. The message text is part of the contract — it is how an admin
     * correlates a suppressed send with the complaints that prompted it.
     */
    public function test_a_suppressed_duplicate_is_logged_with_the_exact_expected_message(): void
    {
        $this->mockSms();

        $settings = $this->fridaySettings();
        $member = $this->makeMember(['phone' => '0241110001']);
        $memberId = $member->id;

        // The other settings row already reserved this member's day.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'reserved by the other settings row',
            'scheduled_at' => Carbon::parse($this->fridayNoon),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => ScheduledSmsDelivery::SOURCE_REMINDER,
            'source_id' => $settings->id,
            'mnotify_job_id' => 'held-by-cloud',
        ]);

        $expected = '[Service Reminder] Skipped duplicate reminder for member '.$memberId
            .' (0241110001) on target date 2026-06-12.';

        Log::spy();

        $this->artisan('reminders:send', ['--at' => $this->fridayNoon, '--force' => true])
            ->assertSuccessful();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with($expected, \Mockery::any());

        $this->assertSame(0, Message::where('recipient_group', 'service_reminder')->count());
    }
}
