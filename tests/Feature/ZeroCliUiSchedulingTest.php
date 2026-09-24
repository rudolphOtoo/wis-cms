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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Zero-CLI UI scheduling guarantee.
 *
 * A church admin configures SMS service reminders purely through the web
 * UI (PUT /api/reminders/settings/{service_type_id}). This suite proves
 * that:
 *
 *   1. The PUT request alone drives the ServiceReminderSettingsObserver →
 *      RecurringSmsScheduler → DispatchScheduledSmsToMnotifyJob chain, all
 *      synchronously (dispatch_sync) inside the request lifecycle.
 *   2. No cron command (sms:sync-rolling-automations / reminders:send) and
 *      no background queue worker is required for the delivery to reach
 *      mNotify: QUEUE_CONNECTION is forced to the production default
 *      ('database') with NO worker running in this test process, and the
 *      jobs table stays empty while scheduled_sms_deliveries lands in
 *      'scheduled_remote' before the HTTP response is returned.
 *   3. No Artisan::call() / artisan invocation appears anywhere in the test
 *      body — the assertions run immediately after putJson() completes.
 */
class ZeroCliUiSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected ServiceType $sundayService;

    protected function setUp(): void
    {
        parent::setUp();

        // Production-parity config: the deploy compose runs the queue on the
        // database connection. There is NO worker in this test process, so a
        // delivery reaching 'scheduled_remote' can only happen via the inline
        // dispatch_sync() path — never via a queued job.
        config([
            'queue.default' => 'database',
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
            'services.mnotify.schedule_days' => 7,
        ]);

        // Monday 2026-08-17 06:00. The next Sunday is 2026-08-23, the next
        // Saturday is 2026-08-22.
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

    /**
     * Fake mNotify's live API. The cancel patterns are only reachable when
     * the observer resync cancels previously scheduled messages on update.
     */
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

    /**
     * The exact HTTP call a church admin triggers from the web UI.
     */
    protected function putSettings(array $payload): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/reminders/settings/{$this->sundayService->id}", $payload);
    }

    public function test_put_configures_reminder_and_locks_remote_schedule_with_zero_cli(): void
    {
        $this->fakeMnotify();

        $response = $this->putSettings($this->upsertPayload());

        $response->assertOk()
            ->assertJsonPath('sync.remote_synced', true)
            ->assertJsonPath('sync.scheduled_count', 2);

        $jobId = $response->json('sync.next_mnotify_job_id');
        $this->assertIsString($jobId);
        $this->assertNotSame('', $jobId);
        $this->assertNotNull($response->json('sync.next_fire_at'));

        // DB is final before the response is returned: every eligible member
        // already holds a scheduled_remote row with a confirmed mNotify job id.
        $settingsId = $response->json('data.id');
        $deliveries = ScheduledSmsDelivery::forSource('reminder', $settingsId)->get();

        $this->assertSame(2, $deliveries->count());
        $deliveries->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);
            $this->assertNotNull($delivery->mnotify_job_id);
            $this->assertTrue($delivery->scheduled_at->isFuture());
            $this->assertSame('2026-08-23', $delivery->scheduled_at->toDateString());
        });

        // Nothing was enqueued for a worker — the inline dispatch_sync() did
        // all the work, so no queue worker is a hard requirement. With the
        // production 'database' queue connection, async dispatches would leave
        // rows here; an empty table proves zero queue dependency.
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_put_update_reschedules_inline_and_lands_scheduled_remote_without_artisan(): void
    {
        $this->fakeMnotify();
        $settingsId = $this->putSettings($this->upsertPayload())->json('data.id');

        // Admin edits the schedule parameters (move to Saturday 10:00) from the UI.
        $this->fakeMnotify(withCancel: true);

        $response = $this->putSettings($this->upsertPayload([
            'template' => 'REMINDER {first_name} — {service_name} at {service_time}!',
            'send_day_of_week' => 6,
            'send_hour' => 10,
            'service_hour' => 10,
        ]));

        $response->assertOk()
            ->assertJsonPath('sync.remote_synced', true)
            ->assertJsonPath('sync.scheduled_count', 2);

        $jobId = $response->json('sync.next_mnotify_job_id');
        $this->assertIsString($jobId);
        $this->assertNotSame('', $jobId);

        // Immediate DB state after the request: no Artisan::call(), no worker.
        $old = ScheduledSmsDelivery::forSource('reminder', $settingsId)
            ->where('status', ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE)
            ->get();
        $this->assertSame(2, $old->count());

        $new = ScheduledSmsDelivery::forSource('reminder', $settingsId)
            ->where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)
            ->get();
        $this->assertSame(2, $new->count());
        $new->each(function (ScheduledSmsDelivery $delivery) {
            $this->assertSame('2026-08-22', $delivery->scheduled_at->toDateString());
            $this->assertSame('10:00', $delivery->scheduled_at->format('H:i'));
            $this->assertStringStartsWith('REMINDER ', $delivery->message_body);
            $this->assertNotNull($delivery->mnotify_job_id);
        });

        $this->assertSame(0, DB::table('jobs')->count());

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/sms/quick')
            && $request['is_schedule'] === true
            && $request['recipient'] !== []
            && in_array($request['recipient'][0], ['0241110001', '0241110002'], true));
    }
}
