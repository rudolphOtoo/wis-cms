<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cloud hygiene + explicit orphan repair.
 *
 * Two failure modes are covered:
 *
 *   1. sms:prune-remote-duplicates — mNotify holds jobs the CMS has no
 *      record of (or holds several copies of one message), so members get
 *      duplicate SMS. The command must cancel ONLY the surplus, never a
 *      job the ledger still owns, and never act on an unread listing.
 *
 *   2. sms:repair-pending-orphans — re-pushes pending_api rows with no
 *      job ID, and is a no-op when there is nothing broken.
 */
class SmsCloudHygieneTest extends TestCase
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
            'queue.default' => 'sync',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-22 06:00:00'));

        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function makeMember(string $name, string $phone): Member
    {
        return Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => $name,
            'last_name' => 'Member',
            'phone' => $phone,
            'status' => 'active',
            'password' => Hash::make('Password@123'),
        ]);
    }

    protected function delivery(Member $member, string $status, ?string $jobId = 'job-1'): ScheduledSmsDelivery
    {
        return ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => 'Hello '.$member->first_name.'! Sunday service at 7:00 AM.',
            'scheduled_at' => Carbon::parse('2026-08-22 12:00:00'),
            'status' => $status,
            'source_type' => 'reminder',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => $jobId,
        ]);
    }

    /**
     * Fake the provider with an explicit remote schedule and record every
     * DELETE that reaches the per-job endpoint.
     */
    protected function fakeProvider(array $remoteJobs, array &$deleted = []): void
    {
        Http::fake(function (Request $request) use ($remoteJobs, &$deleted) {
            $url = $request->url();

            if (preg_match('#/scheduled/([^/?]+)#', $url, $m)) {
                $deleted[] = $m[1];

                return Http::response(['status' => 'success'], 200);
            }

            if (str_contains($url, '/scheduled?') || str_ends_with($url, '/scheduled')) {
                return Http::response(['status' => 'success', 'summary' => $remoteJobs], 200);
            }

            return Http::response(['status' => 'success', 'summary' => []], 200);
        });
    }

    public function test_prune_cancels_duplicate_remote_copies_but_keeps_the_one_the_ledger_owns(): void
    {
        $member = $this->makeMember('Sherry', '0540765021');
        $delivery = $this->delivery($member, ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE);
        $body = $delivery->message_body;

        $remote = [
            ['_id' => '100', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
            ['_id' => '101', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
            ['_id' => '102', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
        ];

        $deleted = [];
        $this->fakeProvider($remote, $deleted);

        $this->artisan('sms:prune-remote-duplicates --execute')->assertSuccessful();

        // The ledger owns exactly one copy, so the first is kept and the
        // other two are surplus.
        $this->assertCount(2, $deleted);
        $this->assertEqualsCanonicalizing(['101', '102'], $deleted);
        $this->assertNotContains('100', $deleted, 'must never cancel a copy the ledger still owns');
    }

    public function test_prune_cancels_jobs_the_ledger_does_not_own_at_all(): void
    {
        // No local rows at all — every future remote job is an orphan.
        $remote = [
            ['_id' => '200', 'date_time' => '2026-08-22 12:00:00', 'message' => 'Ghost message'],
        ];

        $deleted = [];
        $this->fakeProvider($remote, $deleted);

        $this->artisan('sms:prune-remote-duplicates --execute')->assertSuccessful();

        $this->assertSame(['200'], $deleted);
    }

    public function test_prune_never_touches_past_due_or_defused_jobs(): void
    {
        $remote = [
            // Already fired — inert history, must not be touched.
            ['_id' => '300', 'date_time' => '2026-08-01 12:00:00', 'message' => 'Old message'],
            // Defused — parked in 2099, already neutralised.
            ['_id' => '301', 'date_time' => '2099-12-31 07:00:00', 'message' => '(cancelled)'],
        ];

        $deleted = [];
        $this->fakeProvider($remote, $deleted);

        $this->artisan('sms:prune-remote-duplicates --execute')->assertSuccessful();

        $this->assertSame([], $deleted);
    }

    public function test_prune_is_a_dry_run_by_default(): void
    {
        $remote = [
            ['_id' => '400', 'date_time' => '2026-08-22 12:00:00', 'message' => 'Ghost message'],
        ];

        $deleted = [];
        $this->fakeProvider($remote, $deleted);

        $this->artisan('sms:prune-remote-duplicates')->assertSuccessful();

        $this->assertSame([], $deleted, 'a dry run must never cancel anything');
    }

    public function test_prune_defuses_when_delete_is_refused_with_500(): void
    {
        // mNotify's DELETE /scheduled/{id} currently answers HTTP 500 for
        // every job. Parking the job in 2099 is equally effective at stopping
        // delivery, so the prune must still succeed rather than give up.
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (preg_match('#/scheduled/([^/?]+)#', $url, $m)) {
                if (strtoupper($request->method()) === 'DELETE') {
                    return Http::response(['status' => 'error'], 500);
                }

                // Defusal is a PUT reschedule to 2099.
                return Http::response(['status' => 'success'], 200);
            }

            if (str_contains($url, '/scheduled?')) {
                return Http::response(['status' => 'success', 'summary' => [
                    ['_id' => '500', 'date_time' => '2026-08-22 12:00:00', 'message' => 'Ghost message'],
                ]], 200);
            }

            return Http::response(['status' => 'success'], 200);
        });

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('defused to 2099')
            ->assertSuccessful();
    }

    public function test_prune_refuses_to_act_when_the_listing_is_unreadable(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/scheduled?')) {
                return Http::response(['status' => 'error'], 500);
            }

            return Http::response(['status' => 'success'], 200);
        });

        $deleted = [];

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->assertFailed();

        $this->assertSame([], $deleted);
    }

    public function test_repair_reports_clean_when_no_orphans_exist(): void
    {
        $member = $this->makeMember('Sherry', '0540765021');
        $this->delivery($member, ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE);

        $this->fakeProvider([]);

        $this->artisan('sms:repair-pending-orphans --execute')
            ->expectsOutputToContain('Nothing to repair')
            ->assertSuccessful();
    }

    public function test_repair_pushes_orphans_and_leaves_scheduled_rows_alone(): void
    {
        $orphanMember = $this->makeMember('Sherry', '0540765021');
        $orphan = $this->delivery($orphanMember, ScheduledSmsDelivery::STATUS_PENDING_API, null);

        $okMember = $this->makeMember('Albert', '0506145164');
        $ok = $this->delivery($okMember, ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'already-there');

        Http::fake([
            '*/sms/quick*' => Http::response(['status' => 'success', 'summary' => ['id' => 'fresh-job']], 200),
            '*' => Http::response(['status' => 'success', 'summary' => []], 200),
        ]);

        $this->artisan('sms:repair-pending-orphans --execute')->assertSuccessful();

        $orphan->refresh();
        $ok->refresh();

        $this->assertSame(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $orphan->status);
        $this->assertSame('fresh-job', $orphan->mnotify_job_id);

        // The already-confirmed row must not be re-pushed.
        $this->assertSame('already-there', $ok->mnotify_job_id);
    }
}
