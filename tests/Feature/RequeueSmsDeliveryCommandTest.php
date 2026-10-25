<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RequeueSmsDeliveryCommandTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-10-25 06:00:00'));

        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $cloud
     */
    protected function fakeProvider(array &$cloud): void
    {
        Http::fake(function (Request $request) use (&$cloud) {
            $url = $request->url();

            if (str_contains($url, '/scheduled?') && strtoupper($request->method()) === 'GET') {
                return Http::response(['status' => 'success', 'summary' => $cloud], 200);
            }

            if (str_contains($url, '/sms/quick')) {
                $id = 'job-'.bin2hex(random_bytes(4));

                $cloud[] = [
                    '_id' => $id,
                    'date_time' => $request['schedule_date'].':00',
                    'message' => $request['message'],
                    'status' => 'pending',
                ];

                return Http::response([
                    'status' => 'success',
                    'summary' => ['_id' => $id],
                ], 200);
            }

            return Http::response(['status' => 'success', 'summary' => []], 200);
        });
    }

    protected function delivery(
        string $phone,
        string $source,
        string $body,
        string $status,
        ?string $jobId = null,
        string $scheduledAt = '2026-10-30 07:00:00',
    ): ScheduledSmsDelivery {
        return ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $phone,
            'message_body' => $body,
            'scheduled_at' => Carbon::parse($scheduledAt),
            'status' => $status,
            'source_type' => $source,
            'source_id' => $this->branch->id,
            'mnotify_job_id' => $jobId,
        ]);
    }

    public function test_dry_run_does_not_reset_or_push_a_cancelled_birthday(): void
    {
        $cloud = [];
        $this->fakeProvider($cloud);

        $reminder = $this->delivery(
            '0542974953',
            'reminder',
            'Dear member, you are reminded of our Friday prayer service.',
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'reminder-job',
            '2026-10-30 12:00:00',
        );

        $cloud[] = [
            '_id' => 'reminder-job',
            'date_time' => '2026-10-30 12:00:00',
            'message' => $reminder->message_body,
            'status' => 'pending',
        ];

        $birthday = $this->delivery(
            '0542974953',
            'birthday',
            'Happy birthday from your church family!',
            ScheduledSmsDelivery::STATUS_CANCELLED,
        );

        $this->artisan('sms:requeue-delivery '.$birthday->id)
            ->expectsOutputToContain('mNotify does not hold this exact message')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $birthday->fresh()->status);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sms/quick'));
    }

    public function test_execute_pushes_a_cancelled_birthday_while_a_reminder_stays_active(): void
    {
        $cloud = [];
        $this->fakeProvider($cloud);

        $reminder = $this->delivery(
            '0542974953',
            'reminder',
            'Dear member, you are reminded of our Friday prayer service.',
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'reminder-job',
            '2026-10-30 12:00:00',
        );

        $cloud[] = [
            '_id' => 'reminder-job',
            'date_time' => '2026-10-30 12:00:00',
            'message' => $reminder->message_body,
            'status' => 'pending',
        ];

        $birthday = $this->delivery(
            '0542974953',
            'birthday',
            'Happy birthday from your church family!',
            ScheduledSmsDelivery::STATUS_CANCELLED,
        );

        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'scheduled_sms_delivery_id' => $birthday->id,
            'payload' => ['phone' => $birthday->phone],
            'status' => PendingRemoteSchedule::STATUS_PENDING,
        ]);

        $exitCode = Artisan::call('sms:requeue-delivery', [
            'delivery' => $birthday->id,
            '--execute' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Scheduled on mNotify as job', $output);
        $this->assertStringContainsString('mNotify now holds 2 active job(s)', $output);

        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $birthday->fresh()->status);
        $this->assertNotNull($birthday->fresh()->mnotify_job_id);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $reminder->fresh()->status);
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), '/sms/quick')));
        $this->assertCount(2, $cloud);
    }

    public function test_it_refuses_when_the_cloud_already_holds_the_exact_message(): void
    {
        $cloud = [[
            '_id' => '200',
            'date_time' => '2026-10-30 07:00:00',
            'message' => 'Happy birthday from your church family!',
            'status' => 'pending',
        ]];
        $this->fakeProvider($cloud);

        $birthday = $this->delivery(
            '0542974953',
            'birthday',
            'Happy birthday from your church family!',
            ScheduledSmsDelivery::STATUS_CANCELLED,
        );

        $exitCode = Artisan::call('sms:requeue-delivery', [
            'delivery' => $birthday->id,
            '--execute' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Refusing to duplicate it', $output);
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $birthday->fresh()->status);
        $this->assertCount(1, $cloud);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sms/quick'));
    }

    public function test_it_refuses_when_another_active_birthday_owns_the_phone_and_day(): void
    {
        $cloud = [];
        $this->fakeProvider($cloud);

        $active = $this->delivery(
            '0542974953',
            'birthday',
            'Happy birthday (already active)',
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'birthday-job',
        );

        $cloud[] = [
            '_id' => 'birthday-job',
            'date_time' => '2026-10-30 07:00:00',
            'message' => $active->message_body,
            'status' => 'pending',
        ];

        $cancelled = $this->delivery(
            '0542974953',
            'birthday',
            'Happy birthday (cancelled duplicate)',
            ScheduledSmsDelivery::STATUS_CANCELLED,
        );

        $exitCode = Artisan::call('sms:requeue-delivery', [
            'delivery' => $cancelled->id,
            '--execute' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Another active birthday delivery already owns this phone and day', $output);
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $cancelled->fresh()->status);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sms/quick'));
    }
}
