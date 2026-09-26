<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Orphan recovery + batch resilience for the mNotify scheduling pipeline.
 *
 * Regression cover for the incident that stranded 111 of 115 members:
 *
 *   1. isAlreadyScheduled() used to treat pending_api as "already
 *      scheduled", so a row orphaned by an aborted batch was permanently
 *      skipped by every later sync — the member silently never received
 *      the message.
 *   2. dispatchSchedule() rethrew TransientSmsException. Under
 *      QUEUE_CONNECTION=sync that propagated out of the member loop and
 *      aborted every remaining recipient in the batch.
 */
class SmsOrphanRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected ServiceReminderSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mnotify.dry_run' => false,
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
            'services.mnotify.schedule_days' => 7,
            'queue.default' => 'sync',
            'church.birthday.enabled' => true,
        ]);

        // Saturday 06:00 — the 12:00 reminder slot is still ahead.
        Carbon::setTestNow(Carbon::parse('2026-08-22 06:00:00'));

        $this->branch = Branch::factory()->create();

        $type = ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true]
        );

        $this->settings = ServiceReminderSettings::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $type->id,
            'template' => 'Hello {first_name}! {service_name} tomorrow at {service_time}.',
            'send_day_of_week' => Carbon::SATURDAY,
            'send_hour' => 12,
            'service_hour' => 7,
            'service_minute' => 0,
            'is_active' => false,   // create without triggering the observer
        ]);

        // Activate silently — the observer would otherwise push to mNotify
        // during setUp, before Http::fake() is installed.
        ServiceReminderSettings::withoutEvents(function () {
            $this->settings->update(['is_active' => true]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function fakeMnotify(): void
    {
        Http::fake([
            '*/sms/quick*' => Http::response(['status' => 'success', 'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))]], 200),
            '*/scheduled?*' => Http::response(['status' => 'success', 'summary' => []], 200),
            '*/scheduled/*' => Http::response(['status' => 'success'], 200),
            '*/balance*' => Http::response(['status' => 'success', 'summary' => ['balance' => 500]], 200),
            '*' => Http::response(['status' => 'success', 'summary' => []], 200),
        ]);
    }

    protected function makeMember(string $firstName, string $phone): Member
    {
        return Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => $firstName,
            'last_name' => 'Member',
            'phone' => $phone,
            'status' => 'active',
            'password' => Hash::make('Password@123'),
        ]);
    }

    protected function slot(): Carbon
    {
        return Carbon::parse('2026-08-22 12:00:00');
    }

    /**
     * Delivery rows for one member in today's reminder slot only. The
     * rolling window also collects next Saturday, so assertions must be
     * scoped to a single slot.
     */
    protected function slotRows(Member $member)
    {
        return ScheduledSmsDelivery::where('phone', $member->phone)
            ->where('source_type', 'reminder')
            ->where('scheduled_at', $this->slot())
            ->get();
    }

    /**
     * All reminder rows in today's slot, regardless of member.
     */
    protected function slotRowsFor(string $sourceType)
    {
        return ScheduledSmsDelivery::where('source_type', $sourceType)
            ->where('scheduled_at', $this->slot())
            ->get();
    }

    protected function orphanRow(Member $member): ScheduledSmsDelivery
    {
        return ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'Hello '.$member->first_name.'! Sunday Adult Service tomorrow at 7:00 AM.',
            'scheduled_at' => $this->slot(),
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'source_type' => 'reminder',
            'source_id' => $this->settings->id,
        ]);
    }

    /**
     * A pending_api row with no mNotify job ID never reached the provider,
     * so the rolling sync must reclaim and re-push it.
     */
    public function test_rolling_sync_reclaims_pending_rows_that_never_got_a_job_id(): void
    {
        $this->fakeMnotify();

        $member = $this->makeMember('Sherry', '0540765021');
        $orphan = $this->orphanRow($member);

        $this->assertNull($orphan->mnotify_job_id);
        $this->assertSame(ScheduledSmsDelivery::STATUS_PENDING_API, $orphan->status);

        $this->artisan('sms:sync-rolling-automations')->assertSuccessful();

        $orphan->refresh();

        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $orphan->status);
        $this->assertNotNull($orphan->mnotify_job_id);

        // Reclaimed, not duplicated, for this member's slot.
        $this->assertSame(1, $this->slotRows($member)->count());
    }

    /**
     * A row mNotify already accepted must never be pushed twice, even if
     * the local status write is what is missing.
     */
    public function test_pending_row_that_already_has_a_job_id_is_not_re_pushed(): void
    {
        $this->fakeMnotify();

        $member = $this->makeMember('Albert', '0506145164');
        $accepted = $this->orphanRow($member);
        $accepted->update(['mnotify_job_id' => 'already-on-mnotify']);

        $this->artisan('sms:sync-rolling-automations')->assertSuccessful();

        $accepted->refresh();

        $this->assertSame(1, $this->slotRows($member)->count());
        $this->assertSame('already-on-mnotify', $accepted->mnotify_job_id);
    }

    /**
     * The incident: one recipient's push fails transiently, and every
     * recipient after it must still be scheduled.
     */
    public function test_one_transient_failure_does_not_abort_the_remaining_batch(): void
    {
        $members = [];
        foreach (range(1, 5) as $i) {
            $members[] = $this->makeMember("Member{$i}", '054000000'.$i);
        }

        $failFor = $members[1]->phone;
        $failed = 0;

        // Only the 12:00 slot on 2026-08-22 is exercised; the rolling
        // window also collects the following Saturday.
        $slotDate = $this->slot()->format('Y-m-d H:i');

        Http::fake([
            // Callback form: must return an Http\Response, not a raw array.
            '*' => function (Request $request) use ($failFor, &$failed, $slotDate) {
                if (str_contains($request->url(), '/sms/quick')) {
                    $data = $request->data();
                    $recipients = $data['recipient'] ?? [];

                    if (($recipients[0] ?? null) === $failFor && ($data['schedule_date'] ?? '') === $slotDate) {
                        $failed++;

                        // Permanent rejection for exactly one recipient.
                        return Http::response(['status' => 'failed', 'message' => 'rejected'], 200);
                    }

                    return Http::response(
                        ['status' => 'success', 'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))]],
                        200
                    );
                }

                if (str_contains($request->url(), '/balance/')) {
                    return Http::response(['status' => 'success', 'summary' => ['balance' => 500]], 200);
                }

                return Http::response(['status' => 'success', 'summary' => []], 200);
            },
        ]);

        $this->artisan('sms:sync-rolling-automations')->assertSuccessful();

        $slotRows = $this->slotRowsFor('reminder');

        $this->assertCount(5, $slotRows, 'all five members must have a delivery row for the slot');

        // The four healthy recipients must all reach mNotify — the batch
        // must not stop at the failing member.
        $this->assertCount(
            4,
            $slotRows->where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE),
            'recipients after the failing one must still reach mNotify'
        );
        $this->assertSame(1, $failed);
    }

    /**
     * A transient failure is queued for offline retry, so swallowing the
     * exception in the loop never silently drops the message.
     */
    public function test_transient_failure_is_queued_for_offline_retry(): void
    {
        $this->makeMember('Only', '0540765021');

        $slotDate = $this->slot()->format('Y-m-d H:i');

        Http::fake([
            '*' => function (Request $request) use ($slotDate) {
                if (str_contains($request->url(), '/sms/quick')
                    && ($request->data()['schedule_date'] ?? '') === $slotDate) {
                    // 5xx — retry-worthy.
                    return Http::response(['status' => 'error'], 503);
                }

                if (str_contains($request->url(), '/sms/quick')) {
                    return Http::response(
                        ['status' => 'success', 'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))]],
                        200
                    );
                }

                if (str_contains($request->url(), '/balance/')) {
                    return Http::response(['status' => 'success', 'summary' => ['balance' => 500]], 200);
                }

                return Http::response(['status' => 'success', 'summary' => []], 200);
            },
        ]);

        $this->artisan('sms:sync-rolling-automations')->assertSuccessful();

        $this->assertSame(1, PendingRemoteSchedule::where('action', 'schedule')->count());
        $this->assertDatabaseHas('pending_remote_schedules', [
            'action' => 'schedule',
            'status' => 'pending',
        ]);
    }

    /**
     * Deactivating the automation in the UI must issue a real
     * DELETE /scheduled/{id} to mNotify for every remote job.
     */
    public function test_deactivating_automation_deletes_remote_jobs_on_mnotify(): void
    {
        $this->makeMember('Sherry', '0540765021');

        // One callback fake for every endpoint. Http::fake() MERGES across
        // calls and matches patterns in registration order, so a single
        // fake with explicit URL checks keeps listing and per-id routes
        // unambiguous.
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/sms/quick')) {
                return Http::response(
                    ['status' => 'success', 'summary' => ['id' => 'job-'.bin2hex(random_bytes(4))]],
                    200
                );
            }

            if (str_contains($url, '/balance/')) {
                return Http::response(['status' => 'success', 'summary' => ['balance' => 500]], 200);
            }

            // GET /scheduled?key=... — the listing.
            if (str_contains($url, '/scheduled?')) {
                $rows = ScheduledSmsDelivery::query()
                    ->where('source_type', 'reminder')
                    ->where('scheduled_at', $this->slot())
                    ->where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)
                    ->get();

                return Http::response([
                    'status' => 'success',
                    'summary' => $rows->map(fn ($r) => [
                        '_id' => '424242',
                        'date_time' => $this->slot()->format('Y-m-d H:i:s'),
                        'message' => $r->message_body,
                    ])->all(),
                ], 200);
            }

            // DELETE / PUT /scheduled/{id}
            if (preg_match('#/scheduled/(\d+)#', $url)) {
                return Http::response(['status' => 'success'], 200);
            }

            return Http::response(['status' => 'success', 'summary' => []], 200);
        });

        $this->artisan('sms:sync-rolling-automations')->assertSuccessful();

        $delivery = $this->slotRows(Member::first())->firstOrFail();
        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $delivery->status);

        $this->settings->update(['is_active' => false]);

        $delivery->refresh();

        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->status);

        // A real DELETE must have reached mNotify's cloud for this job.
        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/scheduled/424242'));
    }
}
