<?php

namespace App\Console\Commands;

use App\Models\ScheduledSmsDelivery;
use App\Services\RecurringSmsScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Clean up duplicate service reminders already queued for the future.
 *
 * Why this exists
 * ---------------
 * The idempotency guard and the settings dedupe stop new duplicates, but
 * both were added after the fact. Copies created by the old paths are
 * still sitting in `scheduled_sms_deliveries` and — far worse — still held
 * as live jobs on mNotify's own cloud, where they will fire on schedule
 * regardless of anything this application does afterwards. Until those
 * cloud jobs are withdrawn, members keep receiving two or three phrasings
 * of the same reminder.
 *
 * What it treats as a duplicate
 * -----------------------------
 * Grouped by (phone, DATE(scheduled_at)) over future reminder deliveries.
 * One human getting two texts on the same calendar day for the same
 * service is the defect being cleaned, so the grouping key deliberately
 * ignores branch, settings row and message body: two reminders worded
 * differently for the same recipient on the same day are still duplicates.
 *
 * Which copy survives
 * -------------------
 * The single earliest copy that mNotify has actually accepted. "Accepted"
 * is ranked above "earliest" because a row with a job ID is a message the
 * provider owns, while a `pending_api` row may be an orphan from a batch
 * that aborted mid-push — cancelling a provider job and abandoning an
 * orphan are not equivalent acts of housekeeping.
 *
 * How survivors are withdrawn
 * ---------------------------
 * Each surplus row goes through the same CancelScheduledSmsJob the admin
 * "cancel this message" button uses, so the local row and the remote job
 * always end up reconciled. That job already implements the required
 * escalation: DELETE /scheduled/{id} first, and when mNotify refuses
 * (its endpoint currently answers HTTP 500), a PUT that parks the job at
 * 2099-12-31 with an inert body so it can never fire. This command does
 * not re-implement provider handling.
 *
 * Safety
 * ------
 * Dry run unless --execute, and scoped to future dates only: a message
 * that has already been sent cannot be recalled, so past rows are outside
 * both the problem and this command's remit. --execute still asks for
 * confirmation, so a scheduled run needs --force to proceed unattended.
 * Idempotent — a second run finds nothing left to cancel.
 */
class DedupeServiceReminders extends Command
{
    protected $signature = 'sms:dedupe-service-reminders
                            {--execute : Actually withdraw the duplicate deliveries (default: dry run)}
                            {--days= : Only consider deliveries due within N days}
                            {--force : Skip the confirmation prompt (required when running non-interactively)}';

    protected $description = 'Withdraw duplicate service reminders already queued for the same member and day';

    /** How many rows of the plan table to print before summarising the rest. */
    private const TABLE_LIMIT = 60;

    public function __construct(
        protected RecurringSmsScheduler $scheduler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $this->line('=== SERVICE REMINDER DEDUPE ===');
        $this->line('Mode: '.($execute ? 'EXECUTE (duplicates will be withdrawn)' : 'DRY RUN (use --execute to apply)'));
        $this->line('');

        $surplus = $this->findSurplusCopies();

        if ($surplus->isEmpty()) {
            $this->info('No duplicate service reminders found. Nothing to withdraw.');

            return self::SUCCESS;
        }

        $groups = $surplus->countBy('group_key');
        $recipients = $groups->count();

        $this->line("Duplicate deliveries found: {$surplus->count()}");
        $this->line("Recipients affected     : {$recipients}");
        $this->line('');

        $this->renderPlan($surplus);

        if (! $execute) {
            $this->line('');
            $this->comment('Dry run. '.$surplus->count().' delivery/deliveries would be withdrawn.');
            $this->comment('Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        // Withdrawing is destructive and only reversible by re-queueing from
        // the settings screen, so require a deliberate yes. --force is the
        // non-interactive equivalent — a scheduled run cannot answer a
        // prompt, and should say so out loud rather than silently no-op.
        if (! $this->option('force')
            && ! $this->confirm("Withdraw {$surplus->count()} duplicate delivery/deliveries now?", false)) {
            $this->comment('Aborted. Nothing was withdrawn.');

            return self::SUCCESS;
        }

        return $this->withdraw($surplus);
    }

    /**
     * Every future reminder delivery that is a surplus copy of a slot
     * someone else already holds.
     *
     * @return Collection<int, array{delivery: ScheduledSmsDelivery, group_key: string, keep_id: string}>
     */
    protected function findSurplusCopies(): Collection
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;

        $rows = ScheduledSmsDelivery::query()
            ->where('source_type', ScheduledSmsDelivery::SOURCE_REMINDER)
            ->whereIn('status', ScheduledSmsDelivery::IN_FLIGHT_STATUSES)
            ->where('scheduled_at', '>=', now())
            ->when($days !== null, fn ($q) => $q->where('scheduled_at', '<=', now()->addDays($days)))
            ->orderBy('scheduled_at')
            ->orderBy('created_at')
            ->get();

        return $rows
            ->groupBy(fn (ScheduledSmsDelivery $d) => $this->slotKey($d))
            ->flatMap(function (Collection $copies) {
                if ($copies->count() < 2) {
                    return null;
                }

                $survivor = $this->pickSurvivor($copies);
                $key = $this->slotKey($survivor);

                return $copies
                    ->reject(fn (ScheduledSmsDelivery $d) => $d->id === $survivor->id)
                    ->map(fn (ScheduledSmsDelivery $d) => [
                        'delivery' => $d,
                        'group_key' => $key,
                        'keep_id' => $survivor->id,
                    ]);
            })
            ->filter()
            ->values();
    }

    /**
     * Pick the copy that stays: earliest, and among equals the one mNotify
     * has actually accepted.
     *
     * $copies arrives already ordered by scheduled_at then created_at, so
     * a stable sort on provider acceptance alone is enough to promote the
     * accepted copy while leaving the original ordering as the tie-break.
     */
    protected function pickSurvivor(Collection $copies): ScheduledSmsDelivery
    {
        return $copies->sortBy(
            fn (ScheduledSmsDelivery $d) => $d->isProviderBacked() ? 0 : 1
        )->first();
    }

    /**
     * Recipient + calendar day. Branch, settings row and wording are all
     * excluded on purpose — see the class docblock.
     */
    protected function slotKey(ScheduledSmsDelivery $delivery): string
    {
        return $delivery->phone.'@'.$delivery->scheduled_at->toDateString();
    }

    /**
     * @param  Collection<int, array{delivery: ScheduledSmsDelivery, group_key: string, keep_id: string}>  $surplus
     */
    protected function renderPlan(Collection $surplus): void
    {
        $this->line('=== WITHDRAWAL PLAN ===');
        $this->line('Showing '.min($surplus->count(), self::TABLE_LIMIT).' of '.$surplus->count().' duplicate(s).');
        $this->table(
            ['Recipient', 'Phone', 'Scheduled', 'mNotify job', 'Status', 'Keeping'],
            $surplus->take(self::TABLE_LIMIT)->map(fn (array $row) => [
                $row['group_key'],
                $row['delivery']->phone,
                $row['delivery']->scheduled_at->format('Y-m-d H:i'),
                $row['delivery']->mnotify_job_id ?: '—',
                $row['delivery']->status,
                substr($row['keep_id'], 0, 8),
            ])->all()
        );

        if ($surplus->count() > self::TABLE_LIMIT) {
            $this->line('  ... and '.($surplus->count() - self::TABLE_LIMIT).' more.');
        }
    }

    /**
     * Flatten a provider error onto one line. Provider exceptions carry the
     * whole HTTP response, and a multi-line reason breaks the plan table
     * apart just where an operator is trying to read it.
     */
    protected function onOneLine(string $message): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_strimwidth($collapsed, 0, 160, '…');
    }

    /**
     * Withdraw every surplus copy, isolating each one so a single
     * unreachable job cannot abandon the rest of the cleanup.
     *
     * @param  Collection<int, array{delivery: ScheduledSmsDelivery, group_key: string, keep_id: string}>  $surplus
     */
    protected function withdraw(Collection $surplus): int
    {
        $this->line('');
        $this->line('=== WITHDRAWING '.$surplus->count().' DUPLICATE(S) ===');

        $withdrawn = 0;
        $failed = 0;
        $failedIds = [];

        foreach ($surplus as $row) {
            $delivery = $row['delivery'];

            if (! $delivery->isCancellable()) {
                $this->line(sprintf(
                    '[%s] -> skipped: status %s is no longer cancellable.',
                    $delivery->id,
                    $delivery->status
                ));
                $failed++;
                $failedIds[] = $delivery->id;

                continue;
            }

            try {
                // Same path as an admin cancelling a single message:
                // DELETE, escalating to a 2099 defusal if refused, with the
                // local row reconciled to match either way.
                //
                // reconcileCancellation() rather than cancelDeliveryOnMnotify()
                // because the latter swallows failures by design. This loop
                // has to tell the operator *why* a duplicate is still live.
                $outcome = $this->scheduler->reconcileCancellation($delivery);
            } catch (\Throwable $e) {
                $outcome = [
                    'status' => $delivery->fresh()?->status,
                    'withdrawn' => false,
                    'remote' => false,
                    'reason' => $e->getMessage(),
                ];
            }

            if (! $outcome['withdrawn']) {
                // Still live: either the job refused to cancel (it may have
                // already fired, which no local write can undo) or the
                // provider was unreachable and the retry was deferred.
                $this->line(sprintf(
                    '[%s] -> NOT withdrawn (still %s)%s',
                    $delivery->id,
                    $outcome['status'] ?? 'missing',
                    $outcome['reason']
                        ? ': '.$this->onOneLine($outcome['reason'])
                        : '. It may already have fired.'
                ));
                $failed++;
                $failedIds[] = $delivery->id;

                continue;
            }

            $withdrawn++;
            $note = $outcome['remote'] ? 'withdrawn on mNotify' : 'withdrawn locally';
            $this->line(sprintf('[%s] -> %s (%s)', $delivery->id, $note, $outcome['status']));
        }

        $this->line('');
        $this->line("Withdrawn: {$withdrawn}   Failed: {$failed}");

        if ($failedIds !== []) {
            $this->warn('Still live and needing another pass: '.implode(', ', $failedIds));
        }

        Log::info('sms:dedupe-service-reminders completed', [
            'withdrawn' => $withdrawn,
            'failed' => $failed,
            'failed_ids' => $failedIds,
        ]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
