<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PUT /api/reminders/settings/{service_type_id} must configure AND
 * immediately sync the reminder automation with mNotify — the schedule is
 * pushed synchronously inside the request, before the API responds, so no
 * cron job (the 05:00 sms:sync-rolling-automations) is needed. The response
 * carries a `sync` payload so the UI can show a "Synced to mNotify — safe
 * to turn off machine" badge.
 */
class ReminderSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected ServiceType $sundayService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
            'services.mnotify.schedule_days' => 7,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-17 06:00:00'));

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

        $this->sundayService = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true]
        );

        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ama', 'last_name' => 'Mensah',
            'gender' => 'female', 'status' => 'active', 'phone' => '0241110001',
        ]);
        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Kofi', 'last_name' => 'Boateng',
            'gender' => 'male', 'status' => 'active', 'phone' => '0241110002',
        ]);
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

    protected function upsertPayload(array $attrs = []): array
    {
        return array_merge([
            'template' => 'Hello {first_name}! {service_name} tomorrow at {service_time}.',
            'send_day_of_week' => 0,
            'send_hour' => 9,
            'service_hour' => 9,
            'service_minute' => 0,
            'is_active' => true,
        ], $attrs);
    }

    protected function putSettings(array $payload): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/reminders/settings/{$this->sundayService->id}", $payload);
    }

    public function test_put_configures_and_immediately_syncs_with_mnotify_before_response(): void
    {
        $this->fakeMnotify();

        $response = $this->putSettings($this->upsertPayload());

        $response->assertOk();

        // The sync completed INSIDE the request — no cron needed.
        $response->assertJsonPath('sync.remote_synced', true)
            ->assertJsonPath('sync.scheduled_count', 2)
            ->assertJsonPath('data.service_type_id', $this->sundayService->id);

        $this->assertNotNull($response->json('sync.next_fire_at'));
        $this->assertNotNull($response->json('sync.next_mnotify_job_id'));

        // DB is already final: scheduled_remote with a confirmed job id.
        $deliveries = ScheduledSmsDelivery::where('source_type', 'reminder')->where('source_id', $response->json('data.id'))->get();
        $this->assertSame(2, $deliveries->count());
        $deliveries->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame('2026-08-23', $delivery->scheduled_at->toDateString());
            $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
            $this->assertNotNull($delivery->mnotify_job_id);
        });

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sms/quick')
            && $request['is_schedule'] === true
            && $request['recipient'] !== []);
    }

    public function test_put_edit_cancels_old_and_reschedules_with_sync_status(): void
    {
        $this->fakeMnotify();
        $settingsId = $this->putSettings($this->upsertPayload())->json('data.id');

        $this->assertSame(2, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)->count());

        $this->fakeMnotify(withCancel: true);

        $response = $this->putSettings($this->upsertPayload([
            'template' => 'NEW {first_name} — see you at {service_name}, {service_time}!',
            'send_day_of_week' => 6,
            'send_hour' => 10,
        ]));

        $response->assertOk()
            ->assertJsonPath('sync.remote_synced', true)
            ->assertJsonPath('sync.scheduled_count', 2);

        // Old Sunday-9AM jobs cancelled on mNotify...
        $old = ScheduledSmsDelivery::where('source_type', 'reminder')
            ->where('source_id', $settingsId)
            ->where('status', ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE)
            ->get();
        $this->assertSame(2, $old->count());

        // ...and fresh Saturday-10AM jobs created + synced in the same request.
        $new = ScheduledSmsDelivery::where('source_type', 'reminder')
            ->where('source_id', $settingsId)
            ->where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)
            ->get();
        $this->assertSame(2, $new->count());
        $new->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame('2026-08-22', $delivery->scheduled_at->toDateString());
            $this->assertSame('10:00', $delivery->scheduled_at->format('H:i'));
            $this->assertStringStartsWith('NEW ', $delivery->message_body);
            $this->assertNotNull($delivery->mnotify_job_id);
        });

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/scheduled/'));
    }

    public function test_put_with_is_active_false_reports_not_synced(): void
    {
        $this->fakeMnotify();
        $this->putSettings($this->upsertPayload());

        $this->fakeMnotify(withCancel: true);

        $response = $this->putSettings($this->upsertPayload(['is_active' => false, 'send_day_of_week' => 6]));

        $response->assertOk()
            ->assertJsonPath('sync.remote_synced', false)
            ->assertJsonPath('sync.scheduled_count', 0);

        $this->assertSame(0, ScheduledSmsDelivery::where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)->count());
    }
}
