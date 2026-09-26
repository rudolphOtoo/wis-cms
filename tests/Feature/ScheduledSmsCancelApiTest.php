<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin control over individual scheduled dispatches.
 *
 * The whole point of these endpoints is that a cancel is not cosmetic: the
 * message lives on mNotify's cloud schedule, so a locally "cancelled" row
 * whose provider job is still queued will still be delivered. Every test
 * here therefore asserts on the HTTP call made to mNotify, not just on the
 * local status string.
 */
class ScheduledSmsCancelApiTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-22 06:00:00'));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function delivery(string $status, ?string $jobId, ?string $sourceId = null): ScheduledSmsDelivery
    {
        return ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0540765021',
            'message_body' => 'Hello! Sunday service at 7:00 AM.',
            'scheduled_at' => Carbon::parse('2026-08-22 12:00:00'),
            'status' => $status,
            'source_type' => 'reminder',
            'source_id' => $sourceId ?? (string) Str::uuid(),
            'mnotify_job_id' => $jobId,
        ]);
    }

    /**
     * Fake the provider, recording the numeric id used on the DELETE.
     *
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
                    ['_id' => '213781', 'date_time' => '2026-08-22 12:00:00', 'message' => 'Hello! Sunday service at 7:00 AM.'],
                ]], 200);
            }

            return Http::response(['status' => 'success'], 200);
        });
    }

    public function test_cancelling_a_cloud_delivery_deletes_the_mnotify_job(): void
    {
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'push-ref-abc');

        $deleted = [];
        $this->fakeProvider($deleted);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('cloud_cancelled', true)
            ->assertJsonPath('data.status', ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE)
            ->assertJsonPath('data.status_label', 'Cancelled on mNotify');

        // The whole reason this endpoint exists.
        $this->assertSame(['213781'], $deleted);

        $this->assertSame(
            ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
            $delivery->fresh()->status
        );
    }

    public function test_cancelling_a_pending_delivery_makes_no_cloud_call(): void
    {
        // Never accepted by the provider, so there is nothing to delete.
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_PENDING_API, null);

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertOk()
            ->assertJsonPath('cloud_cancelled', false)
            ->assertJsonPath('data.status_label', 'Cancelled');

        $this->assertSame([], $deleted);
        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED, $delivery->fresh()->status);
    }

    public function test_already_sent_messages_cannot_be_cancelled(): void
    {
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_DISPATCHED, 'push-ref-abc');

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('data.is_cancellable', false);

        $this->assertSame([], $deleted);
    }

    public function test_cancelling_is_idempotent(): void
    {
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'push-ref-abc');

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertOk();

        // Second attempt must not hit the provider again.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertStatus(409);

        $this->assertSame(['213781'], $deleted);
    }

    public function test_another_branch_cannot_cancel_our_deliveries(): void
    {
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'push-ref-abc');

        $otherBranch = Branch::factory()->create();
        $outsider = User::create([
            'branch_id' => $otherBranch->id,
            'name' => 'Outsider',
            'email' => 'outsider@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $outsider->assignRole('super_admin');

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel")
            ->assertForbidden();

        $this->assertSame([], $deleted);
    }

    public function test_unauthenticated_users_cannot_cancel(): void
    {
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'push-ref-abc');

        $deleted = [];
        $this->fakeProvider($deleted);

        $this->postJson("/api/sms/scheduled/{$delivery->id}/cancel")->assertUnauthorized();

        $this->assertSame([], $deleted);
    }

    public function test_index_reports_cloud_state_per_delivery(): void
    {
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'push-ref-abc');
        $this->delivery(ScheduledSmsDelivery::STATUS_PENDING_API, null);

        $deleted = [];
        $this->fakeProvider($deleted);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/sms/scheduled?days=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.cancellable', 2)
            ->assertJsonPath('meta.not_on_cloud', 1);
    }

    public function test_cancel_reports_honestly_and_queues_a_retry_when_mnotify_refuses(): void
    {
        $delivery = $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'push-ref-abc');

        // mNotify's DELETE endpoint is known to 500 intermittently, and the
        // defusal PUT fails too. The API must not claim the cloud is clean.
        $deleted = [];
        $this->fakeProvider($deleted, deleteStatus: 500);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/sms/scheduled/{$delivery->id}/cancel");

        $response->assertStatus(202)
            ->assertJsonPath('cloud_cancelled', false)
            ->assertJsonPath('retry_queued', true);

        // The withdraw is persisted for automatic retry, not lost.
        $this->assertSame(1, PendingRemoteSchedule::query()
            ->where('action', PendingRemoteSchedule::ACTION_CANCEL)
            ->where('scheduled_sms_delivery_id', $delivery->id)
            ->count());

        // Local status is untouched, so a later run can still find it.
        $this->assertSame(
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            $delivery->fresh()->status
        );
    }

    public function test_deactivating_a_reminder_setting_cancels_every_future_delivery(): void
    {
        $serviceType = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true]
        );

        $settings = ServiceReminderSettings::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $serviceType->id,
            'template' => 'Hello! Sunday service at {service_time}.',
            'send_day_of_week' => 6,
            'send_hour' => 12,
            'service_hour' => 7,
            'service_minute' => 0,
            'is_active' => true,
        ]);

        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'push-ref-abc', $settings->id);
        $this->delivery(ScheduledSmsDelivery::STATUS_PENDING_API, null, $settings->id);

        $deleted = [];
        $this->fakeProvider($deleted);

        // Toggling is_active must route through the observer.
        $settings->is_active = false;
        $settings->save();

        $this->assertSame(0, ScheduledSmsDelivery::query()->whereIn('status', [
            ScheduledSmsDelivery::STATUS_PENDING_API,
            ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
        ])->count());

        // The uploaded job must have been withdrawn from the cloud.
        $this->assertSame(['213781'], $deleted);
    }
}
