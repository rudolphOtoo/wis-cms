<?php

namespace App\Console\Commands;

use App\Exceptions\TransientSmsException;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile mNotify's cloud schedule against the local delivery ledger and
 * defuse any remote job the CMS does not own.
 *
 * Why this exists
 * ---------------
 * mNotify keeps its own copy of every scheduled job. The CMS mirrors that
 * state in `scheduled_sms_deliveries`, but nothing ever reconciled the two
 * directions. When a batch aborts mid-dispatch, or a settings edit
 * re-pushes an automation, the cloud can end up holding jobs the database
 * has no record of — and those jobs still fire, so members receive the
 * same message two or three times.
 *
 * mNotify has no "list by campaign" filter that is reliable here, so jobs
 * are matched by their natural key: exact `date_time` + message body. For
 * each future, non-defused remote job we compare the number of remote rows
 * against the number of local deliveries expected for that key:
 *
 *   remote count > expected  -> surplus copies, prune the extras
 *   remote count > 0, expected = 0 -> orphan, prune all
 *   remote count <= expected -> keep (never prune a job we cannot prove
 *                               is surplus)
 *
 * Jobs already dispatched (past date_time) and defused jobs (parked at
 * 2099-12-31 with a "(canceled)" body) are left alone: they are inert and
 * deleting them is pure risk.
 *
 * The command is deliberately conservative — it will only ever remove
 * remote jobs, never create or modify local rows, and it defaults to a
 * dry run.
 *
 * COVERAGE SAFETY FLOOR
 * ---------------------
 * "No local delivery for this message" is only evidence that a remote job
 * is unwanted when the local database is genuinely the ledger that
 * scheduled the cloud. Run against a fresh, partial, or simply wrong
 * database, every legitimately scheduled reminder reads as an orphan and
 * this command would defuse the church's entire upcoming SMS run — as
 * "hygiene". That is exactly what a coverage floor prevents.
 *
 * Before any cancellation, the command measures what share of the
 * deliverable cloud jobs the ledger can actually account for (matched by
 * the same natural key used for classification). Below the threshold in
 * `services.mnotify.safety_threshold` it reports, logs, and exits
 * successfully without touching mNotify. Threshold is configurable via
 * MNOTIFY_PRUNE_SAFETY_THRESHOLD; --force bypasses a single run for an
 * operator who has verified the jobs by other means.
 */
class PruneRemoteSmsDuplicates extends Command
{
    protected $signature = 'sms:prune-remote-duplicates
                            {--execute : Actually cancel the surplus/orphan jobs (default: dry run)}
                            {--force : Prune even when the local ledger does not cover enough of the cloud schedule}
                            {--date= : Only consider remote jobs scheduled on this date (Y-m-d)}
                            {--hours= : Only consider remote jobs due within N hours}';

    protected $description = 'Cancel duplicate/orphaned scheduled SMS jobs on mNotify that the local ledger does not own';

    /** Local statuses that represent a message the CMS expects to be live. */
    private const LIVE_STATUSES = [
        ScheduledSmsDelivery::STATUS_PENDING_API,
        ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
    ];

    /** Local statuses that represent a message the CMS already withdrew. */
    private const WITHDRAWN_STATUSES = [
        ScheduledSmsDelivery::STATUS_CANCELLED,
        ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
    ];

    /** Used when the configured threshold is missing or nonsensical. */
    private const DEFAULT_THRESHOLD = 0.25;

    public function handle(MnotifySmsService $sms): int
    {
        $execute = (bool) $this->option('execute');

        $this->line('=== mNotify REMOTE SCHEDULE RECONCILIATION ===');
        $this->line('Mode: '.($execute ? 'EXECUTE (remote jobs will be cancelled)' : 'DRY RUN (use --execute to apply)'));
        $this->line('');

        $jobs = $this->fetchRemoteJobs($sms);

        if ($jobs === null) {
            $this->error('Could not read the mNotify schedule. Aborting — nothing was changed.');

            return self::FAILURE;
        }

        $this->line("Remote jobs on mNotify: {$jobs->count()}");

        $candidates = $this->selectCandidates($jobs);

        if ($candidates->isEmpty()) {
            $this->info('No duplicate or orphaned future jobs found. Cloud and ledger agree.');

            return self::SUCCESS;
        }

        $expectedByKey = $this->expectedCountsByKey();
        $withdrawnByKey = $this->withdrawnCountsByKey();
        $seenByKey = [];
        $surplus = [];

        foreach ($candidates as $job) {
            $key = $this->naturalKey($job);
            $expected = $expectedByKey[$key] ?? 0;
            $seen = $seenByKey[$key] ?? 0;
            $seenByKey[$key] = $seen + 1;

            // Keep the first N copies; everything beyond that is surplus.
            if ($seen >= $expected) {
                $surplus[] = ['job' => $job, 'key' => $key, 'expected' => $expected];
            }
        }

        if ($surplus === []) {
            $this->info('No duplicate or orphaned future jobs found. Cloud and ledger agree.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('=== JOBS TO CANCEL ===');

        foreach ($surplus as $entry) {
            $job = $entry['job'];
            $this->line(sprintf(
                '  _id=%-8s %s  expected=%d  %s',
                $job['_id'],
                $job['date_time'],
                $entry['expected'],
                $this->truncate((string) ($job['message'] ?? ''))
            ));
        }

        $this->line('');
        $this->line(count($surplus).' job(s) '.($execute ? 'will be cancelled.' : 'would be cancelled.'));

        if (! $execute) {
            $this->comment('Dry run. Re-run with --execute to cancel these jobs on mNotify.');

            return self::SUCCESS;
        }

        // Last gate before anything is mutated on the provider. A dry run
        // above is safe to run anywhere, so the floor only gates real work.
        if (! $this->coverageIsSufficient($candidates, $expectedByKey, $withdrawnByKey)) {
            return self::SUCCESS;
        }

        return $this->cancelAll($sms, $surplus);
    }

    /**
     * Refuse to prune when the local ledger cannot account for enough of
     * the deliverable cloud schedule.
     *
     * Coverage is measured as "deliverable cloud jobs this ledger can
     * explain" — those matching a live or withdrawn local delivery on the
     * natural key — over all deliverable cloud jobs. Counting local rows
     * instead would be fooled by stale rows for messages that already
     * dispatched: those inflate the numerator while explaining none of the
     * jobs actually at risk.
     *
     * Aborts with a successful exit code. A scheduled safety abort is not
     * a command failure, and reporting one as a failure would make the
     * scheduler's failure monitoring cry wolf on every misconfigured host.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  array<string, int>  $expectedByKey
     * @param  array<string, int>  $withdrawnByKey
     */
    protected function coverageIsSufficient(Collection $candidates, array $expectedByKey, array $withdrawnByKey): bool
    {
        $threshold = $this->safetyThreshold();
        $deliverable = $candidates->count();

        $accounted = $candidates->filter(function (array $job) use ($expectedByKey, $withdrawnByKey) {
            $key = $this->naturalKey($job);

            return ($expectedByKey[$key] ?? 0) > 0 || ($withdrawnByKey[$key] ?? 0) > 0;
        })->count();

        $coverage = $deliverable > 0 ? $accounted / $deliverable : 1.0;
        $percent = number_format($coverage * 100, 2);
        $floor = number_format($threshold * 100, 0);

        if ($coverage >= $threshold) {
            $this->line(sprintf(
                'Ledger coverage: %s%% of %d active mNotify cloud job(s) accounted for. Floor %s%%.',
                $percent,
                $deliverable,
                $floor,
            ));

            return true;
        }

        if ($this->option('force')) {
            $this->warn('');
            $this->warn('--force: pruning at '.$percent.'% ledger coverage (floor '.$floor.'%).');
            $this->warn('Cancelling on the operator\'s own authority — confirm these jobs really are unwanted.');

            Log::warning('[SMS Prune Safety Guard] Bypassed by --force: local DB accounts for '.$accounted
                .' of '.$deliverable.' active mNotify cloud jobs ('.$percent.'% coverage). '
                .'Minimum required is '.$floor.'%.', [
                    'coverage_percent' => $percent,
                    'threshold_percent' => $floor,
                    'accounted_cloud_jobs' => $accounted,
                    'active_cloud_jobs' => $deliverable,
                ]);

            return true;
        }

        $this->warn('');
        $this->warn('[SMS Prune Safety Guard] Prune aborted — nothing was cancelled on mNotify.');
        $this->warn("  Local DB accounts for {$accounted} of {$deliverable} active mNotify cloud job(s) ({$percent}% coverage).");
        $this->warn("  Minimum required is {$floor}% (services.mnotify.safety_threshold).");
        $this->warn('');
        $this->warn('Below this floor, "no local delivery" stops being evidence that a job is');
        $this->warn('unwanted — it just means this database is not the one that scheduled the');
        $this->warn('messages. Pruning now would defuse real reminders that are still meant to send.');
        $this->warn('');
        $this->warn('Fix the ledger (check DB_DATABASE / DB_HOST), lower the floor via');
        $this->warn('MNOTIFY_PRUNE_SAFETY_THRESHOLD, or re-run with --force if you have verified');
        $this->warn('these jobs by other means.');

        Log::warning('[SMS Prune Safety Guard] Aborted daily prune: local DB accounts for '.$accounted
            .' of '.$deliverable.' active mNotify cloud jobs ('.$percent.'% coverage). '
            .'Minimum required is '.$floor.'%.', [
                'coverage_percent' => $percent,
                'threshold_percent' => $floor,
                'accounted_cloud_jobs' => $accounted,
                'active_cloud_jobs' => $deliverable,
                'forced' => false,
            ]);

        return false;
    }

    /**
     * The configured coverage floor, falling back to the default when it is
     * absent or nonsensical. Values outside 0..1 are rejected rather than
     * silently honoured, so a typo cannot quietly disable the guard.
     */
    protected function safetyThreshold(): float
    {
        $configured = config('services.mnotify.safety_threshold');

        if ($configured === null) {
            return self::DEFAULT_THRESHOLD;
        }

        $value = (float) $configured;

        if ($value < 0.0 || $value > 1.0) {
            Log::warning('[SMS Prune Safety Guard] Ignoring out-of-range MNOTIFY_PRUNE_SAFETY_THRESHOLD, using default.', [
                'configured' => $configured,
                'default' => self::DEFAULT_THRESHOLD,
            ]);

            return self::DEFAULT_THRESHOLD;
        }

        return $value;
    }

    /**
     * Read the raw remote schedule, or null when the provider is
     * unreachable (never prune on incomplete data).
     *
     * @return Collection<int, array<string, mixed>>|null
     */
    protected function fetchRemoteJobs(MnotifySmsService $sms): ?Collection
    {
        try {
            $jobs = $sms->fetchScheduledJobs();
        } catch (TransientSmsException $e) {
            $this->error('mNotify unreachable: '.$e->getMessage());

            return null;
        }

        return collect($jobs);
    }

    /**
     * Narrow the remote schedule to jobs that could still send a message:
     * future-dated, and not already defused.
     */
    protected function selectCandidates(Collection $jobs): Collection
    {
        $dateFilter = $this->option('date');
        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : null;
        $horizon = $hours !== null ? now()->addHours($hours) : null;

        return $jobs->filter(function (array $job) use ($dateFilter, $horizon) {
            $dateTime = (string) ($job['date_time'] ?? '');

            if ($dateTime === '') {
                return false;
            }

            // Defused jobs are parked in the far future and are inert.
            if (str_starts_with($dateTime, '2099')) {
                return false;
            }

            $when = Carbon::parse($dateTime);

            if ($when->isPast()) {
                return false;
            }

            if ($horizon && $when->greaterThan($horizon)) {
                return false;
            }

            if ($dateFilter && ! $when->isSameDay(Carbon::parse($dateFilter))) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * How many live remote copies the local ledger expects for each
     * (date_time, body) key.
     *
     * @return array<string, int>
     */
    protected function expectedCountsByKey(): array
    {
        return ScheduledSmsDelivery::query()
            ->whereIn('status', self::LIVE_STATUSES)
            ->where('scheduled_at', '>=', now())
            ->get(['scheduled_at', 'message_body'])
            ->groupBy(fn ($row) => $this->keyFromParts(
                $row->scheduled_at->format('Y-m-d H:i:s'),
                (string) $row->message_body
            ))
            ->map->count()
            ->all();
    }

    /**
     * How many cancelled local deliveries exist for each (date_time, body)
     * key.
     *
     * Used by the coverage guard: a cloud job the ledger has explicitly
     * withdrawn still proves the ledger describes this account, so it
     * counts as accounted-for coverage even though it contributes nothing
     * to the expected live copy count.
     *
     * @return array<string, int>
     */
    protected function withdrawnCountsByKey(): array
    {
        return ScheduledSmsDelivery::query()
            ->whereIn('status', self::WITHDRAWN_STATUSES)
            ->where('scheduled_at', '>=', now())
            ->get(['scheduled_at', 'message_body'])
            ->groupBy(fn ($row) => $this->keyFromParts(
                $row->scheduled_at->format('Y-m-d H:i:s'),
                (string) $row->message_body
            ))
            ->map->count()
            ->all();
    }

    /**
     * @param  array{job: array<string, mixed>, key: string, expected: int}  $entries
     */
    protected function cancelAll(MnotifySmsService $sms, array $entries): int
    {
        $cancelled = 0;
        $defused = 0;
        $failed = 0;

        $this->line('');
        $this->line('=== CANCELLING ===');

        foreach ($entries as $entry) {
            $id = (string) $entry['job']['_id'];

            try {
                $ok = $sms->cancelScheduled($id);

                if (! $ok) {
                    $ok = $this->defuse($sms, $id);
                    $defused += $ok ? 1 : 0;
                }

                $cancelled += $ok ? 1 : 0;
                $failed += $ok ? 0 : 1;

                $this->line(sprintf('  _id=%-8s %s', $id, $ok ? 'cancelled' : 'FAILED'));
            } catch (TransientSmsException $e) {
                // mNotify's DELETE /scheduled/{id} currently answers HTTP 500
                // for every job, so this is the normal path, not an edge case.
                // Fall back to defusing — rescheduling to 2099-12-31 — which
                // is just as effective at stopping delivery, because a job
                // parked in the far future never fires.
                $this->line(sprintf('  _id=%-8s DELETE failed (%s); defusing instead...', $id, $e->getMessage()));

                try {
                    if ($this->defuse($sms, $id)) {
                        $cancelled++;
                        $defused++;

                        $this->line(sprintf('  _id=%-8s defused to 2099 (withdrawn from delivery)', $id));

                        continue;
                    }
                } catch (TransientSmsException $defuseException) {
                    $e = $defuseException;
                }

                $failed++;
                $this->line(sprintf('  _id=%-8s transient failure: %s', $id, $e->getMessage()));
                Log::warning('sms:prune-remote-duplicates could not cancel job', [
                    'mnotify_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->line("Cancelled: {$cancelled}   Defused (DELETE refused): {$defused}   Failed: {$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Neutralise a remote job by parking it in the far future.
     */
    protected function defuse(MnotifySmsService $sms, string $id): bool
    {
        return $sms->defuseScheduled($id);
    }

    /**
     * Natural key identifying a scheduled message on mNotify: the exact
     * send moment plus the body. This is the only stable correlation the
     * provider exposes, and it is what resolveScheduledJobId() already
     * relies on.
     */
    protected function naturalKey(array $job): string
    {
        return $this->keyFromParts(
            (string) ($job['date_time'] ?? ''),
            (string) ($job['message'] ?? '')
        );
    }

    protected function keyFromParts(string $dateTime, string $body): string
    {
        return $dateTime.'||'.trim($body);
    }

    protected function truncate(string $text, int $length = 52): string
    {
        return mb_strlen($text) > $length
            ? mb_substr($text, 0, $length - 1).'…'
            : $text;
    }
}
