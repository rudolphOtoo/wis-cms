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
 * Audit mNotify's cloud schedule, classify every job, and purge the
 * duplicate / withdrawn messages the CMS no longer wants to send.
 *
 * Why this exists
 * ---------------
 * mNotify holds its own copy of every scheduled SMS. The CMS mirrors that
 * state in `scheduled_sms_deliveries`, but a batch that aborts mid-push,
 * or a settings edit that re-pushes an automation, leaves the cloud
 * holding extra copies. Those jobs still fire, so members receive the
 * same message two or three times.
 *
 * Two classes of unwanted remote job:
 *
 *   DUPLICATE — the CMS still expects this message, but mNotify holds
 *               more copies of it than the ledger has rows. Every copy
 *               beyond the expected count is surplus.
 *   ORPHAN    — the CMS has already withdrawn the message (its local
 *               rows are cancelled / cancelled_remote, or it has no row
 *               for that (date, body) key at all) yet the cloud job is
 *               still pending. Withdrawn messages are the dangerous
 *               kind: a member cancelled their reminder, or a birthday
 *               broadcast was edited, and the cloud never heard about it.
 *
 * Classification is by natural key — exact `date_time` + message body —
 * because that is the only correlation mNotify exposes; its scheduling
 * response returns a reference that does NOT match the `_id` used by
 * GET /scheduled. Job IDs the ledger *does* hold are honoured verbatim
 * and always kept.
 *
 * Two guards make a mass purge impossible:
 *
 *   1. Jobs that can no longer send anything are never touched —
 *      already-dispatched (past `date_time`), and defused jobs parked
 *      at 2099-12-31 with a "(cancelled)" body.
 *   2. If the ledger cannot account for the cloud at all, "no local
 *      delivery found" is not evidence that a message was withdrawn — it
 *      is what every correctly scheduled message looks like when the
 *      command is pointed at the wrong database. Orphans are then
 *      reported but not cancelled unless --force. Surplus *duplicates*
 *      are exempt: they are proven against rows the ledger does hold, and
 *      an empty ledger produces none, so --only-duplicates is safe to
 *      run against an unfamiliar account.
 *
 * Like sms:prune-remote-duplicates this only ever removes remote jobs —
 * it never creates or modifies local rows — and it is a dry run unless
 * --execute is passed.
 */
class PurgeMnotifyCloudDuplicates extends Command
{
    protected $signature = 'mnotify:purge-cloud-duplicates
                            {--execute : Actually cancel the duplicate/orphaned jobs (default: dry run)}
                            {--only-duplicates : Purge only the Duplicate classification and never orphans}
                            {--force : Purge even when the local ledger does not cover the cloud schedule}
                            {--date= : Only consider cloud jobs scheduled on this date (Y-m-d)}
                            {--days= : Only consider cloud jobs due within N days}';

    protected $description = 'Classify and purge duplicate/orphaned scheduled SMS jobs on mNotify against the local delivery ledger';

    /** Local statuses that mean the CMS still expects this message to go out. */
    private const LIVE_STATUSES = [
        ScheduledSmsDelivery::STATUS_PENDING_API,
        ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
    ];

    /** Local statuses that mean the CMS already withdrew this message. */
    private const WITHDRAWN_STATUSES = [
        ScheduledSmsDelivery::STATUS_CANCELLED,
        ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
    ];

    private const KEEP = 'Keep';

    private const DUPLICATE = 'Duplicate';

    private const ORPHAN = 'Orphan';

    /** How many rows of the summary table to print before summarising the rest. */
    private const TABLE_LIMIT = 60;

    public function handle(MnotifySmsService $sms): int
    {
        $execute = (bool) $this->option('execute');

        $this->line('=== mNotify CLOUD DUPLICATE PURGE ===');
        $this->line('Mode: '.($execute ? 'EXECUTE (cloud jobs will be cancelled)' : 'DRY RUN (use --execute to apply)'));
        $this->line('Account: '.config('services.mnotify.sender_id').' via '.rtrim((string) config('services.mnotify.base_url'), '/'));
        $this->line('');

        $before = $this->fetchRemoteJobs($sms);

        if ($before === null) {
            $this->error('Could not read the mNotify schedule. Aborting — nothing was changed.');

            return self::FAILURE;
        }

        $candidates = $this->selectCandidates($before);

        $this->line('Cloud jobs held by mNotify : '.$before->count());
        $this->line('  pending / still deliverable: '.$candidates->count());
        $this->line('  inert (already sent or defused): '.($before->count() - $candidates->count()));
        $this->line('');

        if ($candidates->isEmpty()) {
            $this->info('No deliverable cloud jobs found. Nothing to purge.');

            return self::SUCCESS;
        }

        $ledger = $this->localLedgerIndex();
        $this->line('Local ledger future live deliveries: '.$ledger['live']->keys()->count()
            .' ('.$ledger['live']->sum().' expected copies across '.$ledger['live']->keys()->count().' distinct messages)');
        $this->line('Local ledger withdrawn deliveries  : '.$ledger['withdrawn']->sum());
        $this->line('');

        $classified = $this->classify($candidates, $ledger);

        $this->renderTable($classified);

        $summary = $this->summarise($classified);

        $this->line('');
        $this->line("Keep: {$summary['keep']}   Duplicate: {$summary['duplicate']}   Orphan: {$summary['orphan']}");
        $this->line('Recipients at risk of a duplicate send: '.$summary['recipients']);

        $purgeable = $classified->where('classification', '!=', self::KEEP);

        if ($this->option('only-duplicates')) {
            $skipped = $purgeable->where('classification', self::ORPHAN)->count();
            $purgeable = $purgeable->where('classification', self::DUPLICATE);

            if ($skipped > 0) {
                $this->line('');
                $this->comment("--only-duplicates: {$skipped} orphan(s) will NOT be touched.");
                $this->comment('Orphan only means "no local row found", which is not evidence a message was withdrawn.');
            }
        }

        if ($purgeable->isEmpty()) {
            $this->info('');
            $this->info($this->option('only-duplicates')
                ? 'No duplicate copies found. Nothing to purge.'
                : 'Cloud and ledger agree. No duplicate or orphaned jobs.');

            return self::SUCCESS;
        }

        if (! $execute) {
            $this->line('');
            $this->comment('Dry run. '.$purgeable->count().' job(s) would be cancelled.');
            $this->comment('Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        if (! $this->ledgerCoversCloud($ledger, $candidates, $purgeable)) {
            return self::FAILURE;
        }

        return $this->purge($sms, $purgeable, $before);
    }

    /**
     * Read the raw remote schedule, or null when the provider is
     * unreachable. Never classify — let alone purge — on incomplete data.
     *
     * @return Collection<int, array<string, mixed>>|null
     */
    protected function fetchRemoteJobs(MnotifySmsService $sms): ?Collection
    {
        try {
            return collect($sms->fetchScheduledJobs());
        } catch (TransientSmsException $e) {
            $this->error('mNotify unreachable: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Narrow the cloud schedule to jobs that could still send a message.
     *
     * Everything else is inert: a past `date_time` means mNotify already
     * dispatched it, and a job parked at 2099-12-31 with a "(cancelled)"
     * body was defused by an earlier purge. Cancelling either is pure
     * risk with no member benefit.
     */
    protected function selectCandidates(Collection $jobs): Collection
    {
        $dateFilter = $this->option('date');
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $horizon = $days !== null ? now()->addDays($days) : null;

        return $jobs->filter(function (array $job) use ($dateFilter, $horizon) {
            $dateTime = (string) ($job['date_time'] ?? '');

            if ($dateTime === '' || $this->isDefused($job)) {
                return false;
            }

            // mNotify marks anything it has not yet sent as pending;
            // completed/failed rows are history, not risk.
            $status = strtolower((string) ($job['status'] ?? 'pending'));
            if ($status !== '' && $status !== 'pending') {
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

    protected function isDefused(array $job): bool
    {
        return str_starts_with((string) ($job['date_time'] ?? ''), '2099')
            || trim((string) ($job['message'] ?? '')) === '(cancelled)';
    }

    /**
     * Count the local live and withdrawn deliveries per natural key.
     *
     * @return array{live: Collection<string, int>, withdrawn: Collection<string, int>, owned_job_ids: array<string, string>, live_total: int}
     */
    protected function localLedgerIndex(): array
    {
        $rows = ScheduledSmsDelivery::query()
            ->where('scheduled_at', '>=', now())
            ->get(['scheduled_at', 'message_body', 'status', 'mnotify_job_id']);

        $live = collect();
        $withdrawn = collect();
        $ownedJobIds = [];

        foreach ($rows as $row) {
            $key = $this->keyFromParts(
                $row->scheduled_at->format('Y-m-d H:i:s'),
                (string) $row->message_body
            );

            if (in_array($row->status, self::LIVE_STATUSES, true)) {
                $live[$key] = ($live[$key] ?? 0) + 1;

                if ($row->mnotify_job_id) {
                    $ownedJobIds[(string) $row->mnotify_job_id] = $key;
                }

                continue;
            }

            if (in_array($row->status, self::WITHDRAWN_STATUSES, true)) {
                $withdrawn[$key] = ($withdrawn[$key] ?? 0) + 1;
            }
        }

        return [
            'live' => $live,
            'withdrawn' => $withdrawn,
            'owned_job_ids' => $ownedJobIds,
            'live_total' => $live->sum(),
        ];
    }

    /**
     * Label every deliverable cloud job.
     *
     * Copies the ledger names by job ID are kept unconditionally and
     * claim their slot in the group's allowance first, so a surplus copy
     * that happens to sort ahead of the ledger's own job is not mistaken
     * for it. Any remaining allowance is handed out positionally across
     * the unnamed copies; everything beyond that is surplus.
     *
     * When the allowance is exhausted, every remaining copy in the group
     * is unwanted — a Duplicate if the ledger still wants some copies of
     * the message, an Orphan if it wants none. The reason distinguishes
     * a message the CMS explicitly withdrew from one it has no record of.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  array{live: Collection<string, int>, withdrawn: Collection<string, int>, owned_job_ids: array<string, string>, live_total: int}  $ledger
     * @return Collection<int, array<string, mixed>>
     */
    protected function classify(Collection $candidates, array $ledger): Collection
    {
        $byKey = $candidates->groupBy(fn (array $job) => $this->naturalKey($job));
        $rows = [];

        foreach ($byKey as $jobs) {
            $key = $this->naturalKey($jobs->first());
            $expected = (int) ($ledger['live'][$key] ?? 0);
            $withdrawn = (int) ($ledger['withdrawn'][$key] ?? 0);

            $owned = $jobs->filter(
                fn (array $job) => isset($ledger['owned_job_ids'][(string) ($job['_id'] ?? '')])
            );
            $unnamed = $jobs->reject(
                fn (array $job) => isset($ledger['owned_job_ids'][(string) ($job['_id'] ?? '')])
            );

            // Copies the ledger names by ID already occupy their slots.
            $allowance = max(0, $expected - $owned->count());
            $position = 0;

            foreach ($owned as $job) {
                $rows[] = $this->row($job, self::KEEP, 'job ID held by a live delivery');
            }

            foreach ($unnamed as $job) {
                $position++;

                if ($position <= $allowance) {
                    $rows[] = $this->row($job, self::KEEP, "copy {$position} of {$expected} the ledger expects");

                    continue;
                }

                if ($expected > 0) {
                    $rows[] = $this->row($job, self::DUPLICATE, "copy {$position} of {$expected} the ledger expects");

                    continue;
                }

                $rows[] = $this->row(
                    $job,
                    self::ORPHAN,
                    $withdrawn > 0
                        ? "withdrawn locally ({$withdrawn} cancelled delivery/deliveries)"
                        : 'no local delivery for this message'
                );
            }
        }

        return collect($rows)->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(array $job, string $classification, string $reason): array
    {
        return [
            'id' => (string) ($job['_id'] ?? ''),
            'scheduled_at' => (string) ($job['date_time'] ?? ''),
            'snippet' => $this->truncate((string) ($job['message'] ?? '')),
            'recipients' => $this->recipientCount($job),
            'classification' => $classification,
            'reason' => $reason,
        ];
    }

    /**
     * How many members this cloud job will message.
     */
    protected function recipientCount(array $job): int
    {
        if (isset($job['total_recipients'])) {
            return (int) $job['total_recipients'];
        }

        if (is_array($job['recipient'] ?? null)) {
            return count($job['recipient']);
        }

        return 1;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @return array{keep: int, duplicate: int, orphan: int, recipients: int}
     */
    protected function summarise(Collection $classified): array
    {
        $summary = ['keep' => 0, 'duplicate' => 0, 'orphan' => 0, 'recipients' => 0];

        foreach ($classified as $row) {
            $summary[strtolower($row['classification'])]++;

            if ($row['classification'] !== self::KEEP) {
                $summary['recipients'] += $row['recipients'];
            }
        }

        return $summary;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     */
    protected function renderTable(Collection $classified): void
    {
        $this->line('=== CLOUD JOB CLASSIFICATION ===');
        $this->line('Showing '.$classified->count().' deliverable job(s).');

        $shown = $classified->slice(0, self::TABLE_LIMIT);

        $this->table(
            ['Job ID', 'Scheduled', 'Message', 'Recipients', 'Classification', 'Reason'],
            $shown->map(fn (array $row) => [
                $row['id'],
                $row['scheduled_at'],
                $row['snippet'],
                (string) $row['recipients'],
                $this->colourFor($row['classification'], $row['classification']),
                $row['reason'],
            ])->all()
        );

        if ($classified->count() > self::TABLE_LIMIT) {
            $remaining = $classified->slice(self::TABLE_LIMIT);
            $this->line(sprintf(
                '  ... and %d more (%d Duplicate, %d Orphan)',
                $remaining->count(),
                $remaining->where('classification', self::DUPLICATE)->count(),
                $remaining->where('classification', self::ORPHAN)->count(),
            ));
        }
    }

    /**
     * Refuse to purge unless the ledger credibly describes this account.
     *
     * The two classifications do not rest on the same evidence:
     *
     *   DUPLICATE — mNotify holds more copies of a message than the ledger
     *               has rows for. The surplus is proven by the ledger and
     *               stands on its own.
     *   ORPHAN    — "no local delivery for this message". This is only
     *               evidence of a withdrawn message if the ledger is
     *               actually the ledger that scheduled the cloud. If it
     *               is not (a dev machine holding production credentials,
     *               or a restore that lost the ledger), every genuinely
     *               scheduled message reads as an orphan.
     *
     * The live account holds 776 deliverable jobs while a dev ledger may
     * hold three rows. Reconciling that gap purges the church's entire
     * upcoming SMS schedule and calls it hygiene. So require the ledger
     * to account for a meaningful share of the cloud before "unmatched"
     * is allowed to mean "withdrawn"; below the floor, report and stop.
     */
    private const MIN_LEDGER_COVERAGE = 0.25;

    /**
     * @param  array{live: Collection<string, int>, withdrawn: Collection<string, int>, owned_job_ids: array<string, string>, live_total: int}  $ledger
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  Collection<int, array<string, mixed>>  $purgeable
     */
    protected function ledgerCoversCloud(array $ledger, Collection $candidates, Collection $purgeable): bool
    {
        $deliverable = $candidates->count();

        $corroborated = $candidates->filter(function (array $job) use ($ledger) {
            $key = $this->naturalKey($job);

            return ($ledger['live'][$key] ?? 0) > 0 || ($ledger['withdrawn'][$key] ?? 0) > 0;
        })->count();

        $coverage = $deliverable > 0 ? $corroborated / $deliverable : 1.0;
        $percent = number_format($coverage * 100, 2);

        $duplicates = $purgeable->where('classification', self::DUPLICATE)->count();
        $orphans = $purgeable->where('classification', self::ORPHAN)->count();

        if ($coverage >= self::MIN_LEDGER_COVERAGE) {
            $this->line(sprintf(
                'Ledger coverage: %s%% (%d of %d deliverable cloud jobs matched to a local delivery — %d live, %d withdrawn).',
                $percent,
                $corroborated,
                $deliverable,
                (int) $ledger['live_total'],
                $ledger['withdrawn']->sum(),
            ));

            return true;
        }

        if ($this->option('only-duplicates')) {
            // A wrong ledger cannot manufacture duplicates: the Duplicate
            // classification requires at least one live local row for the
            // exact (date_time, body), so an empty or foreign ledger yields
            // zero duplicates and purges nothing. The residual risk is a
            // ledger that is right about the message but wrong about the
            // copy count — hence the warning, not a block.
            $this->line('');
            $this->warn('--only-duplicates: ledger coverage is only '.$percent.'%, which is unusual.');
            $this->warn('Purging is limited to the '.$duplicates.' job(s) proven surplus against local rows.');
            $this->warn('If this database is not the one that scheduled the cloud, its copy counts may');
            $this->warn('understate what is needed and a wanted copy could be cancelled. Check');
            $this->warn('DB_DATABASE / DB_HOST if the duplicate count looks wrong.');

            Log::warning('mnotify:purge-cloud-duplicates purging duplicates under low ledger coverage', [
                'coverage_percent' => $percent,
                'deliverable_cloud_jobs' => $deliverable,
                'corroborated_cloud_jobs' => $corroborated,
                'duplicates' => $duplicates,
                'orphans_skipped' => $orphans,
                'live_local_deliveries' => (int) $ledger['live_total'],
            ]);

            return true;
        }

        if ($this->option('force')) {
            $this->warn('--force: proceeding at '.$percent.'% ledger coverage ('.$duplicates.' duplicate, '.$orphans.' orphan).');
            $this->warn('Cancelling '.$purgeable->count().' job(s) on the operator\'s own authority.');

            Log::critical('mnotify:purge-cloud-duplicates purging a poorly-covered ledger by force', [
                'coverage_percent' => $percent,
                'deliverable_cloud_jobs' => $deliverable,
                'corroborated_cloud_jobs' => $corroborated,
                'duplicates' => $duplicates,
                'orphans' => $orphans,
                'live_local_deliveries' => (int) $ledger['live_total'],
            ]);

            return true;
        }

        $this->error('');
        $this->error('REFUSING TO PURGE — the local ledger does not describe this mNotify account.');
        $this->error("  Deliverable cloud jobs on mNotify    : {$deliverable}");
        $this->error("  Matched to a local delivery          : {$corroborated} ({$percent}%)");
        $this->error('  Minimum required before trusting it  : '.number_format(self::MIN_LEDGER_COVERAGE * 100, 0).'%');
        $this->error('  Live future deliveries in database   : '.(int) $ledger['live_total']);
        $this->error('');
        $this->error("Classification breakdown right now: {$duplicates} Duplicate, {$orphans} Orphan.");
        $this->error('');
        $this->error('The Duplicate figure is sound — those are copies proven surplus against rows');
        $this->error('this ledger does hold. The Orphan figure is NOT: it only means "no local');
        $this->error('delivery found", which is what every real, correctly scheduled message looks');
        $this->error('like when the command is pointed at the wrong database. Cancelling them');
        $this->error('would stop the church\'s upcoming messages from being sent.');
        $this->error('');
        $this->error('Nothing was cancelled. Re-run against the ledger that owns this mNotify');
        $this->error('account (check DB_DATABASE / DB_HOST), or re-run with --force if you have');
        $this->error('independently confirmed all '.$orphans.' orphan(s) really are unwanted.');

        Log::critical('mnotify:purge-cloud-duplicates refused to purge: ledger coverage below floor', [
            'coverage_percent' => $percent,
            'minimum_coverage_percent' => self::MIN_LEDGER_COVERAGE * 100,
            'deliverable_cloud_jobs' => $deliverable,
            'corroborated_cloud_jobs' => $corroborated,
            'duplicates' => $duplicates,
            'orphans' => $orphans,
            'live_local_deliveries' => (int) $ledger['live_total'],
        ]);

        return false;
    }

    /**
     * Cancel every duplicate/orphaned job, falling back to defusing when
     * mNotify refuses the DELETE (it currently answers HTTP 500 for every
     * job — a parked 2099 job is equally effective at stopping delivery).
     *
     * @param  Collection<int, array<string, mixed>>  $purgeable
     * @param  Collection<int, array<string, mixed>>  $before
     */
    protected function purge(MnotifySmsService $sms, Collection $purgeable, Collection $before): int
    {
        $this->line('');
        $this->line('=== CANCELLING '.$purgeable->count().' JOB(S) ===');

        $cancelled = 0;
        $defused = 0;
        $failed = 0;
        $failedIds = [];

        foreach ($purgeable as $row) {
            $id = (string) $row['id'];

            try {
                $result = $sms->deleteScheduledJob($id);

                if ($result['confirmed']) {
                    $cancelled++;
                    $this->reportSuccess($id, 'deleted from mNotify', $result);

                    continue;
                }

                // HTTP 200 can still mean failure, and mNotify currently
                // refuses some deletes outright. Either way the job is
                // still live, so defuse it.
                $this->line(sprintf(
                    '[Job #%s] -> DELETE not confirmed (HTTP %d) %s; defusing instead...',
                    $id,
                    $result['http_status'],
                    $this->encode($result['body']),
                ));
            } catch (TransientSmsException $e) {
                $this->line(sprintf('[Job #%s] -> DELETE failed (%s); defusing instead...', $id, $e->getMessage()));
                $result = null;
            }

            try {
                $defuse = $sms->defuseScheduledJob($id);

                if ($defuse['confirmed']) {
                    $defused++;
                    $cancelled++;
                    $this->reportSuccess($id, 'defused to 2099-12-31 (withdrawn from delivery)', $defuse);

                    continue;
                }

                $failed++;
                $failedIds[] = $id;
                $this->line(sprintf(
                    '[Job #%s] -> FAILED to defuse (HTTP %d) %s',
                    $id,
                    $defuse['http_status'],
                    $this->encode($defuse['body']),
                ));
                Log::error('mnotify:purge-cloud-duplicates could not withdraw job', [
                    'mnotify_job_id' => $id,
                    'http_status' => $defuse['http_status'],
                    'response' => $defuse['body'],
                ]);
            } catch (TransientSmsException $e) {
                $failed++;
                $failedIds[] = $id;
                $this->line(sprintf('[Job #%s] -> FAILED (%s)', $id, $e->getMessage()));
                Log::warning('mnotify:purge-cloud-duplicates could not withdraw job', [
                    'mnotify_job_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->line("Cancelled: {$cancelled}   (defused because DELETE was refused: {$defused})   Failed: {$failed}");

        $this->renderAfter($sms, $before, $failedIds);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{confirmed: bool, http_status: int, body: array<string, mixed>|string}  $result
     */
    protected function reportSuccess(string $id, string $what, array $result): void
    {
        $this->line(sprintf('[Job #%s] -> %s (HTTP %d) %s', $id, $what, $result['http_status'], $this->encode($result['body'])));

        Log::info("mnotify:purge-cloud-duplicates {$what}", [
            'mnotify_job_id' => $id,
            'http_status' => $result['http_status'],
            'response' => $result['body'],
        ]);
    }

    /**
     * Re-read the cloud and print the before/after totals.
     *
     * @param  Collection<int, array<string, mixed>>  $before
     * @param  list<string>  $failedIds
     */
    protected function renderAfter(MnotifySmsService $sms, Collection $before, array $failedIds): void
    {
        $this->line('');
        $this->line('=== CLOUD SCHEDULE: BEFORE / AFTER ===');

        try {
            $after = collect($sms->refreshScheduledJobs());
        } catch (TransientSmsException $e) {
            $this->warn('Could not re-read the mNotify schedule for the after figure: '.$e->getMessage());

            return;
        }

        $beforeDeliverable = $this->selectCandidates($before)->count();
        $afterDeliverable = $this->selectCandidates($after)->count();
        $beforeDefused = $this->countDefused($before);
        $afterDefused = $this->countDefused($after);

        $this->table(
            ['Metric', 'Before', 'After', 'Change'],
            [
                ['Total jobs on mNotify', (string) $before->count(), (string) $after->count(), $this->delta($before->count(), $after->count())],
                ['Deliverable (pending, future)', (string) $beforeDeliverable, (string) $afterDeliverable, $this->delta($beforeDeliverable, $afterDeliverable)],
                ['Already inert (sent / failed)', (string) ($before->count() - $beforeDeliverable - $beforeDefused), (string) ($after->count() - $afterDeliverable - $afterDefused), $this->delta($before->count() - $beforeDeliverable - $beforeDefused, $after->count() - $afterDeliverable - $afterDefused)],
                ['Defused (parked at 2099)', (string) $beforeDefused, (string) $afterDefused, $this->delta($beforeDefused, $afterDefused)],
            ]
        );

        if ($failedIds !== []) {
            $this->warn('Still live on mNotify and needing another pass: '.implode(', ', $failedIds));
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $jobs
     */
    protected function countDefused(Collection $jobs): int
    {
        return $jobs->filter(fn (array $job) => $this->isDefused($job))->count();
    }

    protected function delta(int $before, int $after): string
    {
        $change = $after - $before;

        if ($change === 0) {
            return '0';
        }

        return ($change > 0 ? '+' : '').$change;
    }

    /**
     * Natural key identifying a scheduled message on mNotify: the exact
     * send moment plus the body. This is the only stable correlation the
     * provider exposes, and what resolveScheduledJobId() relies on.
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

    protected function truncate(string $text, int $length = 40): string
    {
        return mb_strlen($text) > $length
            ? mb_substr($text, 0, $length - 1).'…'
            : $text;
    }

    /**
     * @param  array<string, mixed>|string  $body
     */
    protected function encode(array|string $body): string
    {
        return is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    protected function colourFor(string $classification, string $text): string
    {
        return match ($classification) {
            self::DUPLICATE => "<fg=yellow>$text</>",
            self::ORPHAN => "<fg=red>$text</>",
            default => "<fg=green>$text</>",
        };
    }
}
