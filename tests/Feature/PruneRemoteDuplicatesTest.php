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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The coverage safety floor on sms:prune-remote-duplicates.
 *
 * The prune classifies a remote job as surplus when no local delivery
 * accounts for it, then cancels it — falling back to defusing it by
 * rescheduling to 2099-12-31. That inference is only sound when the local
 * database really is the ledger that scheduled the cloud.
 *
 * Run against a fresh, partial, or wrong database it inverts: every
 * legitimate reminder looks like an orphan, and the daily 05:15 cron
 * quietly defuses the church's entire upcoming SMS run while reporting a
 * clean prune. These tests pin the floor that stops it.
 */
class PruneRemoteDuplicatesTest extends TestCase
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
     * Fake the provider, recording every mutation that reaches mNotify.
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

    /**
     * Deliverable cloud jobs the ledger knows nothing about — the shape a
     * wrong database sees for every genuine reminder.
     *
     * @return list<array<string, mixed>>
     */
    protected function unaccountedJobs(string $prefix, int $count, string $at = '2026-08-22 12:00:00'): array
    {
        return array_map(fn (int $i) => [
            '_id' => $prefix.$i,
            'date_time' => $at,
            'message' => 'Dear member '.$i.', Sunday service at 7:00 AM.',
            'status' => 'pending',
        ], range(1, $count));
    }

    /**
     * The regression this guard exists for: a near-empty local database in
     * front of a populated cloud. Every one of the 100 remote jobs would
     * otherwise be cancelled or defused.
     */
    public function test_low_coverage_aborts_before_touching_mnotify(): void
    {
        Log::spy();

        // 100 deliverable cloud jobs, a ledger holding 2 rows for messages
        // the cloud does not contain: 0% accounted coverage.
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'An old birthday wish.', 'stale-1');
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'An old reminder.', 'stale-2');

        $calls = [];
        $this->fakeProvider($this->unaccountedJobs('R', 100), $calls);

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('[SMS Prune Safety Guard] Prune aborted — nothing was cancelled on mNotify.')
            ->expectsOutputToContain('0 of 100 active mNotify cloud job(s) (0.00% coverage)')
            ->expectsOutputToContain('Minimum required is 25%')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted'], 'no remote job may be cancelled under low coverage');
        $this->assertSame([], $calls['defused'], 'no remote job may be defused under low coverage');

        Http::assertNotSent(fn (Request $request) => strtoupper($request->method()) === 'DELETE');
        Http::assertNotSent(fn (Request $request) => strtoupper($request->method()) === 'PUT');
    }

    public function test_low_coverage_abort_is_written_to_the_log_with_the_documented_marker(): void
    {
        Log::spy();

        $calls = [];
        $this->fakeProvider($this->unaccountedJobs('R', 100), $calls);

        $this->artisan('sms:prune-remote-duplicates --execute')->assertSuccessful();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_starts_with($message, '[SMS Prune Safety Guard] Aborted daily prune:')
                && str_contains($message, 'of 100 active mNotify cloud jobs')
                && str_contains($message, '0.00% coverage')
                && str_contains($message, 'Minimum required is 25%'))
            ->once();
    }

    /**
     * The healthy case: the ledger explains most of the cloud, so the prune
     * runs exactly as before.
     */
    public function test_high_coverage_prunes_normally(): void
    {
        $body = 'Hello! Sunday service at 7:00 AM.';

        // The ledger owns this message once; the cloud holds three copies.
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body);

        $calls = [];
        $this->fakeProvider([
            ['_id' => '100', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
            ['_id' => '101', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
            ['_id' => '102', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
        ], $calls);

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('Ledger coverage: 100.00% of 3 active mNotify cloud job(s)')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['101', '102'], $calls['deleted']);
    }

    public function test_high_coverage_prunes_partial_duplicates_beside_unaccounted_jobs(): void
    {
        $body = 'Hello! Sunday service at 7:00 AM.';

        // The ledger owns this message once; the cloud holds three copies.
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, $body, 'job-1');
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'Second accounted message.', 'job-2', '2026-08-24 12:00:00');

        // A1-A3 (3 jobs) and B2 (1 job) are accounted for: 4 of 10 = 40%,
        // comfortably over the 25% floor.
        $calls = [];
        $this->fakeProvider([
            ['_id' => 'A1', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
            ['_id' => 'A2', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
            ['_id' => 'A3', 'date_time' => '2026-08-22 12:00:00', 'message' => $body],
            ['_id' => 'B1', 'date_time' => '2026-08-23 12:00:00', 'message' => 'Second accounted message.'],
            ['_id' => 'B2', 'date_time' => '2026-08-24 12:00:00', 'message' => 'Second accounted message.'],
            ...$this->unaccountedJobs('U', 5, '2026-08-25 12:00:00'),
        ], $calls);

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('Ledger coverage: 40.00% of 10 active mNotify cloud job(s)')
            ->assertSuccessful();

        // A2/A3 are surplus copies of the owned message and B1 is a copy the
        // ledger does not account for at that time — all true orphans.
        // The U jobs are also cancelled: this command has always treated
        // "unaccounted" as unwanted, which is exactly why the coverage floor
        // above has to stop it running against an unfamiliar ledger.
        $this->assertEqualsCanonicalizing(
            ['A2', 'A3', 'B1', 'U1', 'U2', 'U3', 'U4', 'U5'],
            $calls['deleted']
        );
    }

    public function test_force_bypasses_the_coverage_floor(): void
    {
        Log::spy();

        $calls = [];
        $this->fakeProvider($this->unaccountedJobs('R', 100), $calls);

        $this->artisan('sms:prune-remote-duplicates --execute --force')
            ->expectsOutputToContain('--force: pruning at 0.00% ledger coverage')
            ->expectsOutputToContain('_id=R1 ')
            ->assertSuccessful();

        $this->assertCount(100, $calls['deleted'], '--force must prune the full surplus');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, '[SMS Prune Safety Guard] Bypassed by --force'))
            ->once();
    }

    /**
     * A dry run mutates nothing, so the floor must not block it — operators
     * and cron wrappers rely on being able to inspect coverage safely.
     */
    public function test_dry_run_is_never_blocked_by_the_floor(): void
    {
        $calls = [];
        $this->fakeProvider($this->unaccountedJobs('R', 100), $calls);

        $this->artisan('sms:prune-remote-duplicates')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('100 job(s) would be cancelled')
            ->doesntExpectOutputToContain('[SMS Prune Safety Guard] Prune aborted')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
    }

    /**
     * A cloud with nothing to prune is not a misconfiguration; reporting an
     * abort there would cry wolf on every healthy nightly run.
     */
    public function test_clean_cloud_reports_agreement_without_tripping_the_floor(): void
    {
        $calls = [];
        $this->fakeProvider([], $calls);

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('Cloud and ledger agree')
            ->doesntExpectOutputToContain('[SMS Prune Safety Guard]')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
    }

    public function test_threshold_is_configurable(): void
    {
        config(['services.mnotify.safety_threshold' => 0.90]);

        // 50% coverage clears the default 25% floor but not a 90% one.
        $calls = [];
        $this->fakeProvider($this->unaccountedJobs('R', 10), $calls);
        $this->delivery(ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE, 'An old birthday wish.', 'stale-1');

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('[SMS Prune Safety Guard] Prune aborted')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
    }

    /**
     * Coverage counts cloud jobs the ledger explains, not raw local rows.
     * Stale rows for messages that already dispatched inflate a raw count
     * while accounting for none of the jobs actually at risk.
     */
    public function test_stale_local_rows_do_not_inflate_coverage(): void
    {
        // 40 past deliveries the cloud knows nothing about. Counting rows
        // would report 4000% coverage and let the prune run.
        ScheduledSmsDelivery::query()->delete();
        foreach (range(1, 40) as $i) {
            $this->delivery(
                ScheduledSmsDelivery::STATUS_DISPATCHED,
                'Historic message '.$i.'.',
                'old-'.$i,
                now()->subDays(30)->format('Y-m-d H:i:s'),
            );
        }

        $calls = [];
        $this->fakeProvider($this->unaccountedJobs('R', 20), $calls);

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('[SMS Prune Safety Guard] Prune aborted')
            ->expectsOutputToContain('0.00% coverage')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
    }

    public function test_out_of_range_threshold_falls_back_to_the_default(): void
    {
        config(['services.mnotify.safety_threshold' => 42]);

        $calls = [];
        $this->fakeProvider($this->unaccountedJobs('R', 10), $calls);

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('[SMS Prune Safety Guard] Prune aborted')
            ->expectsOutputToContain('Minimum required is 25%')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
    }

    /**
     * Withdrawn local rows prove the ledger describes this account, so they
     * count as accounted coverage even though they contribute nothing to
     * the expected live copy count.
     */
    public function test_locally_cancelled_deliveries_count_as_coverage(): void
    {
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED, 'Withdrawn message one.', 'job-1', '2026-08-22 12:00:00');
        $this->delivery(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, 'Withdrawn message two.', 'job-2', '2026-08-23 12:00:00');

        // W1 and W2 match the cancelled rows: 2 of 4 = 50%.
        $calls = [];
        $this->fakeProvider([
            ['_id' => 'W1', 'date_time' => '2026-08-22 12:00:00', 'message' => 'Withdrawn message one.'],
            ['_id' => 'W2', 'date_time' => '2026-08-23 12:00:00', 'message' => 'Withdrawn message two.'],
            ...$this->unaccountedJobs('U', 2, '2026-08-24 12:00:00'),
        ], $calls);

        $this->artisan('sms:prune-remote-duplicates --execute')
            ->expectsOutputToContain('Ledger coverage: 50.00% of 4 active mNotify cloud job(s)')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['W1', 'W2', 'U1', 'U2'], $calls['deleted']);
    }
}
