<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderLog;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cloud scheduling vs. the local hourly sender must never both fire.
 *
 * The rolling sync pre-schedules every reminder on mNotify's own cloud
 * queue (source_type='reminder', status=scheduled_remote), which is what
 * makes delivery survive the church desktop being powered off. The hourly
 * `reminders:send` command is the fallback for when that push did not
 * happen.
 *
 * These two paths were previously unaware of each other: reminders:send
 * only consulted ServiceReminderLog, which the cloud path never writes, so
 * on a running desktop at the send hour it dispatched a SECOND immediate
 * message on top of the cloud job — every member got two texts.
 */
class ReminderDoubleSendGuardTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected ServiceType $sundayService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
            'queue.default' => 'sync',
        ]);

        // Saturday 12:00 — the exact moment the reminder is configured to fire.
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00'));

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
        $this->sundayService = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true]
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function member(string $phone): Member
    {
        return Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Sherry',
            'last_name' => 'Mensah',
            'phone' => $phone,
            'status' => 'active',
        ]);
    }

    /** Reminder fires Saturday 12:00 for a Sunday service. */
    protected function settings(): ServiceReminderSettings
    {
        return ServiceReminderSettings::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $this->sundayService->id,
            'template' => 'Dear {first_name}, Sunday Divine Service comes off at {service_time}.',
            'send_day_of_week' => 6,
            'send_hour' => 12,
            'service_hour' => 7,
            'service_minute' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Record the mNotify HTTP calls an immediate send would make.
     *
     * @param  list<string>  $sent
     */
    protected function fakeProvider(array &$sent): void
    {
        Http::fake(function (Request $request) use (&$sent) {
            if (str_contains($request->url(), '/sms/quick')) {
                $sent[] = (string) (($request['recipient'][0] ?? '?'));

                return Http::response(['status' => 'success', 'summary' => ['id' => 'direct-'.count($sent)]], 200);
            }

            if (str_contains($request->url(), '/scheduled?')) {
                return Http::response(['status' => 'success', 'summary' => []], 200);
            }

            return Http::response(['status' => 'success', 'summary' => []], 200);
        });
    }

    public function test_it_does_not_resend_a_reminder_already_held_on_the_cloud(): void
    {
        $member = $this->member('0540765021');
        $settings = $this->settings();

        // The rolling sync already reserved this message on mNotify's cloud.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => $settings->render($member, 'Sunday Adult Service', now()->copy()->addDay(), '07:00', $this->branch->name),
            'scheduled_at' => Carbon::parse('2026-10-03 12:00:00'),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => 'reminder',
            'source_id' => $settings->id,
            'mnotify_job_id' => 'cloud-job-1',
        ]);

        $sent = [];
        $this->fakeProvider($sent);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertSame(
            [],
            $sent,
            'A message already reserved on the mNotify cloud must not also be sent immediately — the member would receive two texts.'
        );
    }

    public function test_it_does_not_resend_a_reminder_still_awaiting_cloud_upload(): void
    {
        $member = $this->member('0540765021');
        $settings = $this->settings();

        // pending_api with a job id means the cloud owns it too.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'anything',
            'scheduled_at' => Carbon::parse('2026-10-03 12:00:00'),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'reminder',
            'source_id' => $settings->id,
            'mnotify_job_id' => 'cloud-job-2',
        ]);

        $sent = [];
        $this->fakeProvider($sent);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertSame([], $sent);
    }

    public function test_it_still_sends_when_nothing_is_scheduled_on_the_cloud(): void
    {
        // The fallback path must survive: no cloud row means this command is
        // the only thing that will deliver, so it must fire.
        $member = $this->member('0540765021');
        $this->settings();

        $sent = [];
        $this->fakeProvider($sent);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertCount(1, $sent, 'Without a cloud reservation the local sender must still deliver.');
        $this->assertSame(1, ServiceReminderLog::where('status', ServiceReminderLog::STATUS_SENT)->count());
    }

    public function test_a_cancelled_cloud_row_does_not_suppress_the_fallback_send(): void
    {
        $member = $this->member('0540765021');
        $settings = $this->settings();

        // Cancelled remotely — the cloud will NOT deliver, so the local
        // sender is the last chance to reach this member.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'anything',
            'scheduled_at' => Carbon::parse('2026-10-03 12:00:00'),
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
            'source_type' => 'reminder',
            'source_id' => $settings->id,
            'mnotify_job_id' => 'cloud-job-3',
        ]);

        $sent = [];
        $this->fakeProvider($sent);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertCount(1, $sent);
    }

    public function test_it_only_suppresses_the_matching_member(): void
    {
        $shadowed = $this->member('0540765021');
        $other = $this->member('0506145164');
        $settings = $this->settings();

        // Only one member has a cloud reservation (e.g. the other was added
        // after the last rolling sync). Suppression must be per-recipient.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $shadowed->phone,
            'message_body' => 'anything',
            'scheduled_at' => Carbon::parse('2026-10-03 12:00:00'),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => 'reminder',
            'source_id' => $settings->id,
            'mnotify_job_id' => 'cloud-job-4',
        ]);

        $sent = [];
        $this->fakeProvider($sent);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertCount(1, $sent, 'Only the member with a cloud reservation should be suppressed.');
        $this->assertSame([$other->phone], $sent);
    }
}
