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
 * mnotify:purge-cloud-duplicates — cloud schedule hygiene.
 *
 * The command cancels remote jobs in two situations:
 *
 *   1. DUPLICATE — mNotify holds more copies of a message than the local
 *      ledger expects, so members receive it more than once.
 *   2. ORPHAN    — the CMS withdrew a message (its rows are cancelled) or
 *      has no row for it, yet the cloud job is still pending.
 *
 * Both are irreversible against a live provider, so the tests pin the
 * things that must NOT happen: never touch a job the ledger owns, never
 * touch an inert job, never act on an unread listing, and never purge
 * when the ledger does not describe the account at all.
 */
class MnotifyCloudPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    /** Guarantees unique phone numbers: members are unique per (branch, phone). */
    protected static int $phoneSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$phoneSequence = 0;

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

    protected function delivery(string $status, string $body, ?string $jobId = 'job-1', string $at = '2026-08-22 12:00:00'): ScheduledSmsDelivery
    {
        $member = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Sherry',
            'last_name' => 'Member',
            'phone' => sprintf('054%07d', ++self::$phoneSequence),
            'status' => 'active',
            'password' => Hash::make('Password@123'),
        ]);

        return ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => $member->phone,
            'message_body' => $body,
            'scheduled_at' => Carbon::parse($at),
            'status' => $status,
            'source_type' => 'reminder',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => $jobId,
        ]);
    }

    /**
     * Fake the provider with an explicit cloud schedule, recording every
     * DELETE and PUT that reaches a per-job endpoint.
     *
     * @param  list<array<string, mixed>>  $remoteJobs
     * @param  array{deleted?: list<string>, defused?: list<string>}  $calls
     */
    protected function fakeProvider(array $remoteJobs, array &$calls = [], int $deleteStatus = 200): void
    {
        $calls['deleted'] ??= [];
        $calls['defused'] ??= [];

        Http::fake(function (Request $request) use ($remoteJobs, &$calls, $deleteStatus) {
            $url = $request->url();

            if (preg_match('#/scheduled/([^/?]+)#', $url, $m)) {
                if (strtoupper($request->method()) === 'DELETE') {
                    $calls['deleted'][] = $m[1];

                    return $deleteStatus === 200
                        ? Http::response(['status' => 'success'], 200)
                        : Http::response(['status' => 'error'], $deleteStatus);
                }

                $calls['defused'][] = $m[1];

                return Http::response(['status' => 'success'], 200);
            }

            if (str_contains($url, '/scheduled?')) {
                return Http::response(['status' => 'success', 'summary' => $remoteJobs], 200);
            }

            return Http::response(['status' => 'success', 'summary' => []], 200);
        });
    }

    public function test_purge_cancels_surplus_copies_but_keeps_the_one_the_ledger_owns(): void
    {
        $body = 'Hello! Sunday service at 7:00 AM.';
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body);

        $calls = [];
        $this->fakeProvider([
            ['_id' => '100', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
            ['_id' => '101', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
            ['_id' => '102', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('Duplicate: 2')
            ->expectsOutputToContain('Job #101')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['101', '102'], $calls['deleted']);
        $this->assertNotContains('100', $calls['deleted'], 'must never cancel a copy the ledger still owns');
    }

    public function test_purge_cancels_a_message_the_ledger_withdrew_locally(): void
    {
        // The CMS cancelled this reminder; the cloud job never heard about it.
        $body = 'Dear Edith, service comes off at 7:00 AM.';
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, $body);

        $calls = [];
        $this->fakeProvider([
            ['_id' => '200', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('Orphan: 1')
            ->expectsOutputToContain('Job #200')
            ->assertSuccessful();

        $this->assertSame(['200'], $calls['deleted']);
    }

    public function test_purge_keeps_the_copy_named_by_a_local_job_id_even_when_copies_surround_it(): void
    {
        $body = 'Reminder body.';
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body, '777');

        $calls = [];
        $this->fakeProvider([
            ['_id' => '778', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
            ['_id' => '777', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
            ['_id' => '779', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute')->assertSuccessful();

        $this->assertNotContains('777', $calls['deleted'], 'a job ID the ledger owns must never be cancelled');
        $this->assertCount(2, $calls['deleted']);
    }

    public function test_purge_never_touches_dispatched_or_defused_jobs(): void
    {
        $body = 'Live message.';
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body);

        $calls = [];
        $this->fakeProvider([
            // Already fired — history.
            ['_id' => '300', 'date_time' => '2026-08-01 12:00:00', 'message' => $body, 'status' => 'completed'],
            // Defused by an earlier prune — parked at 2099 with "(cancelled)".
            ['_id' => '301', 'date_time' => '2099-12-31 07:00:00', 'message' => '(cancelled)', 'status' => 'pending'],
            // Failed remotely — inert.
            ['_id' => '302', 'date_time' => '2026-08-05 12:00:00', 'message' => $body, 'status' => 'failed'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('No deliverable cloud jobs found')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
        $this->assertSame([], $calls['defused']);
    }

    public function test_purge_is_a_dry_run_by_default(): void
    {
        $body = 'Withdrawn message.';
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, $body);

        $calls = [];
        $this->fakeProvider([
            ['_id' => '400', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('would be cancelled')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted'], 'a dry run must never cancel anything');
    }

    public function test_purge_reports_recipient_totals_for_the_duplicate_risk(): void
    {
        $body = 'Branch-wide reminder.';
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body);

        $calls = [];
        $this->fakeProvider([
            ['_id' => '500', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
            ['_id' => '501', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('Recipients at risk of a duplicate send: 1')
            ->assertSuccessful();
    }

    public function test_purge_defuses_when_mnotify_refuses_the_delete(): void
    {
        // mNotify's DELETE /scheduled/{id} currently answers HTTP 500 for
        // every job. Parking the job at 2099 stops delivery just as
        // effectively, so the purge must still succeed.
        $body = 'Withdrawn message.';
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, $body);

        $calls = [];
        $this->fakeProvider([
            ['_id' => '600', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'total_recipients' => 1, 'status' => 'pending'],
        ], $calls, deleteStatus: 500);

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('defused to 2099-12-31')
            ->expectsOutputToContain('Cancelled: 1')
            ->assertSuccessful();

        $this->assertSame(['600'], $calls['deleted']);
        $this->assertSame(['600'], $calls['defused']);
    }

    public function test_purge_fails_the_run_when_a_job_cannot_be_withdrawn(): void
    {
        $body = 'Withdrawn message.';
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, $body);

        Http::fake(function (Request $request) use ($body) {
            if (preg_match('#/scheduled/([^/?]+)#', $request->url())) {
                return Http::response(['status' => 'error'], 403);
            }

            if (str_contains($request->url(), '/scheduled?')) {
                return Http::response(['status' => 'success', 'summary' => [
                    ['_id' => '700', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
                ]], 200);
            }

            return Http::response(['status' => 'success'], 200);
        });

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('Failed: 1')
            ->expectsOutputToContain('needing another pass')
            ->assertFailed();
    }

    public function test_purge_refuses_to_act_when_the_listing_is_unreadable(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/scheduled?')) {
                return Http::response(['status' => 'error'], 500);
            }

            return Http::response(['status' => 'success'], 200);
        });

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('Aborting')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request) => strtoupper($request->method()) === 'DELETE');
    }

    /**
     * The critical guard: a cloud the ledger cannot account for means the
     * command is pointed at the wrong database. Purging then would
     * destroy real members' upcoming messages under the label "hygiene".
     */
    public function test_purge_refuses_when_the_ledger_covers_too_little_of_the_cloud(): void
    {
        $body = 'Sunday service at 7:00 AM.';

        // One live delivery against eight unaccounted cloud jobs:
        // 1 of 9 deliverable jobs = 11.11% coverage, far below the floor.
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body);

        $calls = [];
        $this->fakeProvider([
            ['_id' => '800', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
            ['_id' => '801', 'date_time' => '2026-09-05 12:00:00', 'message' => 'Real reminder A.', 'status' => 'pending'],
            ['_id' => '802', 'date_time' => '2026-09-05 12:00:00', 'message' => 'Real reminder B.', 'status' => 'pending'],
            ['_id' => '803', 'date_time' => '2026-09-06 12:00:00', 'message' => 'Real reminder C.', 'status' => 'pending'],
            ['_id' => '804', 'date_time' => '2026-09-06 12:00:00', 'message' => 'Real reminder D.', 'status' => 'pending'],
            ['_id' => '805', 'date_time' => '2026-09-07 12:00:00', 'message' => 'Real reminder E.', 'status' => 'pending'],
            ['_id' => '806', 'date_time' => '2026-09-07 12:00:00', 'message' => 'Real reminder F.', 'status' => 'pending'],
            ['_id' => '807', 'date_time' => '2026-09-08 12:00:00', 'message' => 'Real reminder G.', 'status' => 'pending'],
            ['_id' => '808', 'date_time' => '2026-09-08 12:00:00', 'message' => 'Real reminder H.', 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('REFUSING TO PURGE')
            ->expectsOutputToContain('11.11%')
            ->expectsOutputToContain('Nothing was cancelled')
            ->assertFailed();

        $this->assertSame([], $calls['deleted'], 'a poorly covered ledger must never authorise a purge');
        $this->assertSame([], $calls['defused']);
    }

    public function test_purge_proceeds_when_the_ledger_covers_enough_of_the_cloud(): void
    {
        $body = 'Sunday service at 7:00 AM.';
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body);
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, 'Withdrawn reminder.', 'job-2', '2026-09-05 12:00:00');

        $calls = [];
        $this->fakeProvider([
            // 3 of 4 jobs matched to the ledger = 75% coverage.
            ['_id' => '820', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
            ['_id' => '821', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
            ['_id' => '822', 'date_time' => '2026-09-05 12:00:00', 'message' => 'Withdrawn reminder.', 'status' => 'pending'],
            ['_id' => '823', 'date_time' => '2026-09-06 12:00:00', 'message' => 'Unaccounted.', 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute')
            ->expectsOutputToContain('Ledger coverage: 75.00%')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['821', '822', '823'], $calls['deleted']);
    }

    public function test_purge_proceeds_when_the_operator_forces_a_low_coverage_purge(): void
    {
        $calls = [];
        $this->fakeProvider([
            ['_id' => '900', 'date_time' => '2026-08-22 12:00:00', 'message' => 'Confirmed unwanted.', 'status' => 'pending'],
        ], $calls);

        // No local deliveries at all.
        $this->assertSame(0, ScheduledSmsDelivery::count());

        $this->artisan('mnotify:purge-cloud-duplicates --execute --force')
            ->expectsOutputToContain('--force: proceeding')
            ->expectsOutputToContain('[Job #900]')
            ->assertSuccessful();

        $this->assertSame(['900'], $calls['deleted']);
    }

    /**
     * The live-account case: a ledger holding 3 rows against 776 cloud
     * jobs, where 764 unaccounted jobs are real scheduled messages. Only
     * the duplicates are provably surplus, so only those may go.
     */
    public function test_only_duplicates_purges_surplus_copies_and_never_orphans(): void
    {
        $body = 'Happy birthday Juliet!';

        // The ledger knows this message and expects exactly one copy.
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body);

        $calls = [];
        $this->fakeProvider([
            // 4 remote copies, 1 expected -> 3 surplus.
            ['_id' => 'D1', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
            ['_id' => 'D2', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
            ['_id' => 'D3', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
            ['_id' => 'D4', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
            // Real, correctly scheduled messages the ledger cannot see.
            // Enough of them that coverage (4 of 20 = 20%) sits below the
            // 25% floor that blocks orphan purging.
            ...$this->unaccountedJobs('O', 16),
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute --only-duplicates')
            ->expectsOutputToContain('16 orphan(s) will NOT be touched')
            ->expectsOutputToContain('ledger coverage is only 20.00%')
            ->assertSuccessful();

        $this->assertCount(3, $calls['deleted']);

        foreach (['O1', 'O8', 'O16'] as $orphanId) {
            $this->assertNotContains($orphanId, $calls['deleted'], 'orphans must survive --only-duplicates');
            $this->assertNotContains($orphanId, $calls['defused']);
        }
    }

    /**
     * Cloud jobs the local ledger knows nothing about.
     *
     * @return list<array<string, mixed>>
     */
    protected function unaccountedJobs(string $prefix, int $count): array
    {
        return array_map(fn (int $i) => [
            '_id' => $prefix.$i,
            'date_time' => '2026-09-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).' 12:00:00',
            'message' => 'Real reminder '.$i.'.',
            'status' => 'pending',
        ], range(1, $count));
    }

    public function test_only_duplicates_is_a_no_op_against_an_empty_ledger(): void
    {
        // With nothing in the ledger, expected = 0 everywhere, so every
        // cloud job classifies as an orphan and none can be a duplicate.
        $calls = [];
        $this->fakeProvider([
            ['_id' => '900', 'date_time' => '2026-08-22 12:00:00', 'message' => 'Unaccounted.', 'status' => 'pending'],
        ], $calls);

        $this->assertSame(0, ScheduledSmsDelivery::count());

        $this->artisan('mnotify:purge-cloud-duplicates --execute --only-duplicates')
            ->expectsOutputToContain('No duplicate copies found')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
        $this->assertSame([], $calls['defused']);
    }

    public function test_only_duplicates_does_not_purge_a_locally_withdrawn_message(): void
    {
        // A withdrawn message is an Orphan, not a Duplicate, so
        // --only-duplicates must leave it alone.
        $body = 'Dear Edith, service comes off at 7:00 AM.';
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, $body);

        $calls = [];
        $this->fakeProvider([
            ['_id' => 'W1', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute --only-duplicates')
            ->expectsOutputToContain('1 orphan(s) will NOT be touched')
            ->expectsOutputToContain('No duplicate copies found')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
    }

    public function test_date_filter_narrows_the_purge_to_one_day(): void
    {
        $body = 'Withdrawn message.';
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, $body, 'job-1', '2026-08-22 12:00:00');
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, $body.' Later.', 'job-2', '2026-08-29 12:00:00');

        $calls = [];
        $this->fakeProvider([
            ['_id' => 'A1', 'date_time' => '2026-08-22 12:00:00', 'message' => $body, 'status' => 'pending'],
            ['_id' => 'B1', 'date_time' => '2026-08-29 12:00:00', 'message' => $body.' Later.', 'status' => 'pending'],
        ], $calls);

        $this->artisan('mnotify:purge-cloud-duplicates --execute --date=2026-08-22')->assertSuccessful();

        $this->assertSame(['A1'], $calls['deleted']);
    }
}
