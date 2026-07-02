<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\Message;
use App\Models\ServiceReminderLog;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use App\Services\MnotifySmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the SendServiceReminders command. Mirrors the
 * BirthdayGreetingsTest pattern with service-reminder specifics:
 *   - Settings rows that target a specific (DOW, hour)
 *   - Idempotency via (member, service_type, intended_service_date)
 *   - intended_service_date computed from service type's natural DOW
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

        $this->artisan('reminders:send', ['--at' => $this->saturday8pm])
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

        $this->artisan('reminders:send', ['--at' => $this->saturday8pm])
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
        $this->artisan('reminders:send', ['--at' => $this->saturday8pm])->assertSuccessful();
        $this->assertSame(1, ServiceReminderLog::status('sent')->count());

        // Second run at the same moment: skips (idempotent)
        $this->artisan('reminders:send', ['--at' => $this->saturday8pm])
            ->expectsOutputToContain('1 idempotent-skip')
            ->assertSuccessful();

        // Still only 1 sent log
        $this->assertSame(1, ServiceReminderLog::status('sent')->count());
    }

    public function test_inactive_settings_row_is_ignored(): void
    {
        $this->makeSettings(['is_active' => false]);
        $this->makeMember();

        $this->artisan('reminders:send', ['--at' => $this->saturday8pm])
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
        $this->artisan('reminders:send', ['--at' => $this->saturday8pm])->assertSuccessful();

        $log = ServiceReminderLog::first();
        $this->assertSame('2026-06-14', $log->intended_service_date->toDateString());
    }

    public function test_no_match_when_hour_doesnt_align(): void
    {
        $this->mockSms();
        $this->makeSettings();   // configured for hour 20
        $this->makeMember();

        // Same day, but 7 PM instead of 8 PM
        $this->artisan('reminders:send', ['--at' => '2026-06-13 19:00:00'])
            ->expectsOutputToContain('No reminders configured')
            ->assertSuccessful();

        $this->assertSame(0, ServiceReminderLog::count());
    }
}
