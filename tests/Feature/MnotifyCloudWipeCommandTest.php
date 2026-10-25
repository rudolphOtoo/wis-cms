<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ScheduledSmsDelivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MnotifyCloudWipeCommandTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-08-22 06:00:00'));

        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
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
     * @return list<array<string, mixed>>
     */
    protected function jobs(): array
    {
        return [
            ['_id' => '100', 'date_time' => '2026-08-23 12:00:00', 'message' => 'Pending one', 'status' => 'pending'],
            ['_id' => '101', 'date_time' => '2026-08-24 12:00:00', 'message' => 'Pending two', 'status' => 'scheduled'],
            ['_id' => '102', 'date_time' => '2026-08-20 12:00:00', 'message' => 'Already sent', 'status' => 'sent'],
            ['_id' => '103', 'date_time' => '2026-08-21 12:00:00', 'message' => 'Failed job', 'status' => 'failed'],
        ];
    }

    public function test_dry_run_lists_pending_jobs_without_deleting_them(): void
    {
        $calls = [];
        $this->fakeProvider($this->jobs(), $calls);

        $this->artisan('sms:purge-mnotify-cloud --dry-run')
            ->expectsOutputToContain('Pending jobs to purge : 2')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();

        $this->assertSame([], $calls['deleted']);
        $this->assertSame([], $calls['defused']);
    }

    public function test_purge_deletes_every_pending_job_and_leaves_terminal_history_alone(): void
    {
        $calls = [];
        $this->fakeProvider($this->jobs(), $calls);

        $this->artisan('sms:purge-mnotify-cloud')
            ->expectsOutputToContain('Deleted : 2')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['100', '101'], $calls['deleted']);
        $this->assertNotContains('102', $calls['deleted']);
        $this->assertNotContains('103', $calls['deleted']);
    }

    public function test_purge_defuses_a_job_when_delete_is_unavailable(): void
    {
        $calls = [];
        $this->fakeProvider([
            ['_id' => '200', 'date_time' => '2026-08-23 12:00:00', 'message' => 'Stuck', 'status' => 'pending'],
        ], $calls, 500);

        $this->artisan('sms:purge-mnotify-cloud')
            ->expectsOutputToContain('Defused : 1')
            ->assertSuccessful();

        $this->assertSame(['200'], $calls['deleted']);
        $this->assertSame(['200'], $calls['defused']);
    }

    public function test_purge_marks_a_locally_owned_delivery_cancelled(): void
    {
        $delivery = ScheduledSmsDelivery::create([
            'branch_id' => $this->branch->id,
            'phone' => '0241234567',
            'message_body' => 'Pending one',
            'scheduled_at' => Carbon::parse('2026-08-23 12:00:00'),
            'status' => ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            'source_type' => 'reminder',
            'source_id' => $this->branch->id,
            'mnotify_job_id' => '100',
        ]);

        $calls = [];
        $this->fakeProvider($this->jobs(), $calls);

        $this->artisan('sms:purge-mnotify-cloud')->assertSuccessful();

        $this->assertSame(ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE, $delivery->fresh()->status);
    }

    public function test_status_reports_clean_when_no_pending_jobs_remain(): void
    {
        $this->fakeProvider([
            ['_id' => '102', 'date_time' => '2026-08-20 12:00:00', 'message' => 'Already sent', 'status' => 'sent'],
        ]);

        $this->artisan('sms:check-mnotify-status')
            ->expectsOutputToContain('Pending scheduled messages    : 0')
            ->expectsOutputToContain('CLEAN')
            ->assertSuccessful();
    }

    public function test_status_fails_when_pending_jobs_remain(): void
    {
        $this->fakeProvider($this->jobs());

        $this->artisan('sms:check-mnotify-status')
            ->expectsOutputToContain('Pending scheduled messages    : 2')
            ->expectsOutputToContain('NOT CLEAN')
            ->assertFailed();
    }

    public function test_status_can_ignore_defused_jobs_as_deliverable(): void
    {
        $this->fakeProvider([
            ['_id' => '300', 'date_time' => '2099-12-31 07:00:00', 'message' => '(cancelled)', 'status' => 'pending'],
        ]);

        $this->artisan('sms:check-mnotify-status --deliverable-only')
            ->expectsOutputToContain('Defused (2099)              : 1')
            ->expectsOutputToContain('CLEAN')
            ->assertSuccessful();
    }
}
