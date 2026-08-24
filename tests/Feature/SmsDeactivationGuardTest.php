<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the automated deactivation guard: cancelling or deactivating
 * a service reminder must cancel every pending/remote-scheduled SMS that
 * automation pushed to mNotify — including when mNotify's DELETE endpoint
 * is down (defusal fallback).
 */
class SmsDeactivationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected ServiceReminderSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);

        $type = ServiceType::firstOrCreate(
            ['slug' => 'midweek_service'],
            ['name' => 'Midweek Service', 'type' => 'combined', 'is_active' => true]
        );

        $this->settings = ServiceReminderSettings::create([
            'branch_id' => Branch::factory()->create()->id,
            'service_type_id' => $type->id,
            'template' => 'Hello {first_name}!',
            'send_day_of_week' => 3,
            'send_hour' => 12,
            'service_hour' => 18,
            'service_minute' => 30,
            'is_active' => true,
        ]);
    }

    protected function createLiveDelivery(string $jobId = '205520'): ScheduledSmsDelivery
    {
        return ScheduledSmsDelivery::create([
            'branch_id' => $this->settings->branch_id,
            'phone' => '0241234567',
            'message_body' => 'Hello Sam, Teaching service is underway!',
            'scheduled_at' => now()->addDays(4),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => $jobId,
            'source_type' => 'reminder',
            'source_id' => $this->settings->id,
        ]);
    }

    public function test_deactivating_setting_cancels_remote_jobs(): void
    {
        $delivery = $this->createLiveDelivery();

        Http::fake([
            // mNotify schedule listing used for job-handle resolution
            '*/scheduled?*' => Http::response(['status' => 'success', 'summary' => []], 200),
            '*/scheduled/205520*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->settings->update(['is_active' => false]);

        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/scheduled/205520'));
    }

    public function test_reactivating_setting_resumes_cancelled_deliveries(): void
    {
        $delivery = $this->createLiveDelivery();

        Http::fake([
            '*' => Http::response(['status' => 'success', 'summary' => ['_id' => 'rearmed-001']], 200),
        ]);

        // Deactivate → remote job cancelled (DELETE confirmed)…
        $this->settings->update(['is_active' => false]);
        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);

        // …reactivate → the future delivery is re-armed and re-pushed
        // to mNotify under a fresh job ID instead of staying dead.
        $this->settings->update(['is_active' => true]);

        $this->assertSame(1, ScheduledSmsDelivery::count());
        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
        $this->assertSame('rearmed-001', $delivery->mnotify_job_id);
    }

    public function test_rolling_sync_does_not_resurrect_cancelled_deliveries(): void
    {
        $tomorrow = now()->addDay()->startOfDay();
        $slot = $tomorrow->copy()->hour(12);

        $this->settings->update([
            'send_day_of_week' => $tomorrow->dayOfWeek,
            'send_hour' => 12,
        ]);

        $cancelledMember = Member::factory()->create([
            'branch_id' => $this->settings->branch_id,
            'phone' => '0241110001',
        ]);
        $liveMember = Member::factory()->create([
            'branch_id' => $this->settings->branch_id,
            'phone' => '0241110002',
        ]);

        // Admin cancelled this member's reminder while the automation
        // stayed active — it must act as a tombstone for that date.
        ScheduledSmsDelivery::create([
            'branch_id' => $this->settings->branch_id,
            'phone' => $cancelledMember->phone,
            'message_body' => 'Old cancelled message',
            'scheduled_at' => $slot,
            'status' => ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
            'mnotify_job_id' => '205520',
            'source_type' => 'reminder',
            'source_id' => $this->settings->id,
        ]);

        Http::fake([
            '*' => Http::response(['status' => 'success', 'summary' => ['_id' => 'fresh-job-1']], 200),
        ]);

        $this->artisan('sms:sync-rolling-automations', ['--force' => true])->assertSuccessful();

        $forDate = ScheduledSmsDelivery::query()
            ->where('source_type', 'reminder')
            ->where('source_id', $this->settings->id)
            ->whereDate('scheduled_at', $tomorrow->toDateString());

        // Cancelled member: exactly the tombstone, never recreated.
        $this->assertSame(1, (clone $forDate)->where('phone', $cancelledMember->phone)->count());
        $this->assertSame(
            ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
            (clone $forDate)->where('phone', $cancelledMember->phone)->first()->status
        );

        // Live member: a fresh delivery was still created for them.
        $liveRow = (clone $forDate)->where('phone', $liveMember->phone)->first();
        $this->assertNotNull($liveRow);
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $liveRow->status);
    }

    public function test_deleting_setting_cancels_remote_jobs(): void
    {
        $delivery = $this->createLiveDelivery('205999');

        Http::fake([
            // mNotify schedule listing used for job-handle resolution
            '*/scheduled?*' => Http::response(['status' => 'success', 'summary' => []], 200),
            '*/scheduled/205999*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->settings->delete();

        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);
    }

    public function test_delete_outage_falls_back_to_defusal(): void
    {
        $delivery = $this->createLiveDeploymentJob();

        Http::fake([
            // mNotify schedule listing used for job-handle resolution
            '*/scheduled?*' => Http::response(['status' => 'success', 'summary' => []], 200),
            // DELETE (cancel) crashes provider-side...
            '*/scheduled/205777*' => Http::sequence()
                ->push('<html>server error</html>', 500)
                // ...then PUT (defusal) works.
                ->push(['status' => 'success'], 200),
        ]);

        // Deactivate AFTER registering fakes so the observer's inline
        // cancellation job is intercepted, never reaching the real API.
        $this->settings->update(['is_active' => false]);

        $this->artisan('sms:cancel-deactivated-reminders', ['--force' => true])->assertSuccessful();

        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);
        $this->assertStringContainsString('Defused', $delivery->error_message);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && ($request['schedule_date'] ?? '') !== ''
            && str_starts_with((string) $request['schedule_date'], '2099-12-31'));
    }

    public function test_delete_405_falls_back_to_defusal(): void
    {
        $delivery = $this->createLiveDelivery();

        Http::fake([
            // mNotify schedule listing used for job-handle resolution
            '*/scheduled?*' => Http::response(['status' => 'success', 'summary' => []], 200),
            // DELETE rejected with "method not allowed"...
            '*/scheduled/205520*' => Http::sequence()
                ->push(['error' => 'method not allowed'], 405)
                // ...then PUT defusal succeeds.
                ->push(['status' => 'success'], 200),
        ]);

        $this->settings->update(['is_active' => false]);

        $delivery->refresh();
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);
        $this->assertStringContainsString('Defused', $delivery->error_message);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_starts_with((string) $request['schedule_date'], '2099-12-31'));
    }

    public function test_cancellation_resolves_authoritative_remote_job_handle(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->settings->branch_id,
            'phone' => '0241234567',
            'message_body' => 'Hello Sam, Teaching service is underway!',
            'scheduled_at' => now()->addDays(4)->startOfSecond(),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            // Push-time reference — does NOT match the listing handle.
            'mnotify_job_id' => 'B891A332-BB8F-41A7-B69F-4D0F5377050E',
            'source_type' => 'reminder',
            'source_id' => $this->settings->id,
        ]);
        $delivery = $delivery->fresh();

        Http::fake([
            // Listing exposes the numeric handle mNotify's cancel/defuse
            // endpoints actually accept.
            '*/scheduled?*' => Http::response(['status' => 'success', 'summary' => [
                ['_id' => '206001', 'date_time' => $delivery->scheduled_at->format('Y-m-d H:i:s'), 'message' => $delivery->message_body],
            ]], 200),
            '*/scheduled/206001*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->settings->update(['is_active' => false]);

        $delivery->refresh();
        $this->assertSame('206001', $delivery->mnotify_job_id);
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/scheduled/206001'));
    }

    protected function createLiveDeploymentJob(): ScheduledSmsDelivery
    {
        return $this->createLiveDelivery('205777');
    }
}
