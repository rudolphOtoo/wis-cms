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

class RequeueSmsDayCommandTest extends TestCase
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
     * Fake mNotify with an in-memory cloud schedule so the post-push count
     * is truthful.
     *
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

    protected function delivery(string $phone, string $status = ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE): ScheduledSmsDelivery
    {
        return ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $phone,
            'message_body' => "Midweek service reminder for {$phone}",
            'scheduled_at' => Carbon::parse('2026-10-28 12:00:00'),
            'status' => $status,
            'source_type' => 'reminder',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => $status === ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE ? 'old-'.$phone : null,
        ]);
    }

    public function test_dry_run_does_not_reset_or_push_anything(): void
    {
        $cloud = [];
        $this->fakeProvider($cloud);

        $first = $this->delivery('0240000001');
        $second = $this->delivery('0240000002');

        $this->artisan('sms:requeue-day 2026-10-28')
            ->expectsOutputToContain('mNotify active jobs on this date: 0')
            ->expectsOutputToContain('Local active deliveries on this date: 2')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $first->fresh()->status);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $second->fresh()->status);
        $this->assertSame([], $cloud);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sms/quick'));
    }

    public function test_execute_resets_stale_rows_clears_retries_and_pushes_once_per_recipient(): void
    {
        $cloud = [];
        $this->fakeProvider($cloud);

        $first = $this->delivery('0240000001');
        $second = $this->delivery('0240000002');

        PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_CANCEL,
            'scheduled_sms_delivery_id' => $first->id,
            'payload' => ['mnotify_job_id' => 'old-0240000001'],
            'status' => PendingRemoteSchedule::STATUS_PENDING,
        ]);

        $exitCode = Artisan::call('sms:requeue-day', ['date' => '2026-10-28', '--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Pushed: 2', $output);
        $this->assertStringContainsString('Not scheduled: 0', $output);
        $this->assertStringContainsString('mNotify now holds 2 active job(s) for 2026-10-28', $output);

        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $first->fresh()->status);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $second->fresh()->status);
        $this->assertNotSame('old-0240000001', $first->fresh()->mnotify_job_id);
        $this->assertSame(
            PendingRemoteSchedule::STATUS_COMPLETED,
            PendingRemoteSchedule::query()->firstOrFail()->status,
        );
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_contains($request->url(), '/sms/quick')));
    }

    public function test_execute_allows_birthday_and_reminder_for_the_same_phone_on_the_same_day(): void
    {
        $cloud = [];
        $this->fakeProvider($cloud);

        $scheduledAt = Carbon::parse('2026-10-28 12:00:00');

        $reminder = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0240000009',
            'message_body' => 'Midweek service reminder',
            'scheduled_at' => $scheduledAt,
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => 'reminder',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => 'old-reminder',
        ]);

        $birthday = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0240000009',
            'message_body' => 'Happy birthday from your church family',
            'scheduled_at' => $scheduledAt,
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => 'birthday',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => 'old-birthday',
        ]);

        $exitCode = Artisan::call('sms:requeue-day', ['date' => '2026-10-28', '--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Pushed: 2', $output);
        $this->assertStringContainsString('Not scheduled: 0', $output);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $reminder->fresh()->status);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $birthday->fresh()->status);
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_contains($request->url(), '/sms/quick')));
    }

    public function test_execute_still_blocks_a_second_reminder_for_the_same_phone_and_day(): void
    {
        $cloud = [];
        $this->fakeProvider($cloud);

        $scheduledAt = Carbon::parse('2026-10-28 12:00:00');

        $first = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0240000010',
            'message_body' => 'Midweek service reminder',
            'scheduled_at' => $scheduledAt,
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => 'reminder',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => 'old-first',
        ]);

        $second = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0240000010',
            'message_body' => 'Midweek service reminder (duplicate wording)',
            'scheduled_at' => $scheduledAt,
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => 'reminder',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => 'old-second',
        ]);

        $exitCode = Artisan::call('sms:requeue-day', ['date' => '2026-10-28', '--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Pushed: 1', $output);
        $this->assertStringContainsString('Not scheduled: 1', $output);
        $this->assertSame(
            1,
            collect([$first->fresh(), $second->fresh()])
                ->where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)
                ->count(),
        );
        $this->assertSame(
            1,
            collect([$first->fresh(), $second->fresh()])
                ->where('status', ScheduledSmsDelivery::STATUS_CANCELLED)
                ->count(),
        );
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), '/sms/quick')));
    }

    public function test_it_refuses_to_requeue_when_mnotify_already_holds_a_job_for_that_date(): void
    {
        $cloud = [[
            '_id' => '200',
            'date_time' => '2026-10-28 12:00:00',
            'message' => 'Already in the cloud',
            'status' => 'processing',
        ]];
        $this->fakeProvider($cloud);

        $delivery = $this->delivery('0240000003');

        $exitCode = Artisan::call('sms:requeue-day', ['date' => '2026-10-28', '--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Refusing to requeue', $output);
        $this->assertStringContainsString('mNotify already holds 1 active job(s)', $output);

        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->fresh()->status);
        $this->assertSame('old-0240000003', $delivery->fresh()->mnotify_job_id);
        $this->assertCount(1, $cloud);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sms/quick'));
    }

    public function test_it_changes_nothing_when_the_date_has_no_local_deliveries(): void
    {
        $cloud = [];
        $this->fakeProvider($cloud);

        $this->artisan('sms:requeue-day 2026-10-28 --execute')
            ->expectsOutputToContain('No local queued deliveries found for this date')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sms/quick'));
    }
}
