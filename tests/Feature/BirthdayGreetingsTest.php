<?php

namespace Tests\Feature;

use App\Models\BirthdayMessageLog;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Message;
use App\Services\MnotifySmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class BirthdayGreetingsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        config(['church.birthday.enabled' => true]);
    }

    protected function makeMember(array $attrs = []): Member
    {
        return Member::create(array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ama', 'last_name' => 'Mensah', 'gender' => 'female',
            'status' => 'active', 'phone' => '0241234567',
        ], $attrs));
    }

    public function test_member_with_birthday_today_gets_a_greeting(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->andReturn(true));

        $this->makeMember(['date_of_birth' => '1990-05-28']);

        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])
            ->expectsOutputToContain('dispatched to 1')
            ->assertSuccessful();

        $msg = Message::where('recipient_group', 'birthday')->first();
        $this->assertNotNull($msg);
        $this->assertNull($msg->sender_id, 'Birthday message should be system-sent (null sender)');
        $this->assertStringContainsString('Ama', $msg->body);
        $this->assertSame('delivered', $msg->recipients->first()->delivery_status);
    }

    public function test_member_without_birthday_today_gets_nothing(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->never());

        $this->makeMember(['date_of_birth' => '1990-01-15']); // not today

        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])
            ->expectsOutputToContain('No birthdays today')
            ->assertSuccessful();

        $this->assertSame(0, Message::where('recipient_group', 'birthday')->count());
    }

    public function test_year_is_ignored_only_month_and_day_matter(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->andReturn(true));

        // Born May 28 in different years — all should match a May 28 run
        $this->makeMember(['date_of_birth' => '1975-05-28', 'phone' => '0240000001']);
        $this->makeMember(['date_of_birth' => '2001-05-28', 'phone' => '0240000002']);

        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])->assertSuccessful();

        $this->assertSame(2, Message::where('recipient_group', 'birthday')->count());
    }

    public function test_disabled_config_sends_nothing(): void
    {
        config(['church.birthday.enabled' => false]);
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->never());

        $this->makeMember(['date_of_birth' => '1990-05-28']);

        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame(0, Message::where('recipient_group', 'birthday')->count());
    }

    public function test_inactive_member_gets_no_greeting(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->never());

        $this->makeMember(['date_of_birth' => '1990-05-28', 'status' => 'inactive']);

        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])->assertSuccessful();

        $this->assertSame(0, Message::where('recipient_group', 'birthday')->count());
    }

    public function test_idempotent_when_run_twice_same_day(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->once()->andReturn(true));

        $this->makeMember(['date_of_birth' => '1990-05-28']);

        // First run sends
        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])
            ->expectsOutputToContain('dispatched to 1')
            ->assertSuccessful();

        // Second run on the same day should skip
        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])
            ->expectsOutputToContain('dispatched to 0')
            ->assertSuccessful();

        $this->assertSame(1, Message::where('recipient_group', 'birthday')->count(),
            'Second run must not create a duplicate message');
    }

    public function test_member_without_phone_logged_as_no_phone(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->never());

        $member = $this->makeMember(['date_of_birth' => '1990-05-28', 'phone' => null]);

        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])->assertSuccessful();

        $log = BirthdayMessageLog::where('member_id', $member->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(BirthdayMessageLog::STATUS_NO_PHONE, $log->status);
        $this->assertSame(0, Message::where('recipient_group', 'birthday')->count());
    }

    public function test_sent_messages_create_log_entries(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->andReturn(true));

        $member = $this->makeMember(['date_of_birth' => '1990-05-28']);

        $this->artisan('birthdays:send', ['--date' => '2026-05-28', '--force' => true])->assertSuccessful();

        $log = BirthdayMessageLog::where('member_id', $member->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(BirthdayMessageLog::STATUS_SENT, $log->status);
        $this->assertSame('0241234567', $log->phone_used);
        $this->assertStringContainsString('Ama', $log->message_body);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
