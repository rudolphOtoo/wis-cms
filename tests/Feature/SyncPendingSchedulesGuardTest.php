<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPendingSchedulesGuardTest extends TestCase
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

    public function test_a_single_run_is_bounded_so_boot_cannot_hang_on_a_large_backlog(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'summary' => ['id' => 'queued-job-1'],
        ], 200));

        for ($i = 0; $i < 3; $i++) {
            $delivery = $this->delivery("024123450{$i}");
            $this->pendingSchedule($delivery);
        }

        $this->artisan('sync:pending-schedules --force --limit=2')->assertSuccessful();

        $this->assertSame(2, PendingRemoteSchedule::where('status', PendingRemoteSchedule::STATUS_COMPLETED)->count());
        $this->assertSame(1, PendingRemoteSchedule::where('status', PendingRemoteSchedule::STATUS_PENDING)->count());

        $this->artisan('sync:pending-schedules --force --limit=2')->assertSuccessful();

        $this->assertSame(3, PendingRemoteSchedule::where('status', PendingRemoteSchedule::STATUS_COMPLETED)->count());
    }

    public function test_cancel_backlog_reuses_one_remote_listing_instead_of_fetching_it_per_item(): void
    {
        $listingFetches = 0;

        Http::fake(function (Request $request) use (&$listingFetches) {
            if (str_contains($request->url(), '/scheduled?')) {
                $listingFetches++;

                return Http::response(['status' => 'success', 'summary' => []], 200);
            }

            return Http::response(['status' => 'success'], 200);
        });

        foreach (['0241234510', '0241234511'] as $phone) {
            $delivery = $this->delivery($phone, ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'remote-'.$phone);
            $this->pendingCancel($delivery, 'remote-'.$phone);
        }

        $this->artisan('sync:pending-schedules --force --limit=10')->assertSuccessful();

        $this->assertSame(1, $listingFetches);
        $this->assertSame(2, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE)->count());
    }

    protected function delivery(
        string $phone,
        string $status = ScheduledSmsDelivery::STATUS_PENDING_API,
        ?string $jobId = null,
    ): ScheduledSmsDelivery {
        return ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $phone,
            'message_body' => 'Midweek service reminder',
            'scheduled_at' => Carbon::parse('2026-10-28 07:00:00'),
            'status' => $status,
            'source_type' => 'reminder',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => $jobId,
        ]);
    }

    protected function pendingSchedule(ScheduledSmsDelivery $delivery): PendingRemoteSchedule
    {
        return PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_SCHEDULE,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => [
                'phone' => $delivery->phone,
                'message_body' => $delivery->message_body,
                'scheduled_at' => $delivery->scheduled_at->toIso8601String(),
                'branch_id' => $delivery->branch_id,
            ],
        ]);
    }

    protected function pendingCancel(ScheduledSmsDelivery $delivery, string $jobId): PendingRemoteSchedule
    {
        return PendingRemoteSchedule::create([
            'action' => PendingRemoteSchedule::ACTION_CANCEL,
            'scheduled_sms_delivery_id' => $delivery->id,
            'payload' => ['mnotify_job_id' => $jobId],
        ]);
    }
}
