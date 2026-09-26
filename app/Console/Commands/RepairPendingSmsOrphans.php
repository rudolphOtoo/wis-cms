<?php

namespace App\Console\Commands;

use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-push orphaned pending_api deliveries to mNotify.
 *
 * A delivery is an orphan when it is still pending_api and has no
 * mnotify_job_id — the local row exists but the provider never accepted
 * the message. Historically these were unrecoverable: the idempotency
 * guard in RecurringSmsScheduler treated pending_api as "already
 * scheduled", so neither the rolling sync nor the pending-schedule drain
 * would ever retry them, and the member silently never received the
 * message.
 *
 * This command is the explicit, auditable repair path. It is safe to run
 * repeatedly: a delivery that already holds a job ID is never touched, and
 * rows that are already in the offline retry queue are left to
 * sync:pending-schedules rather than being double-queued.
 */
class RepairPendingSmsOrphans extends Command
{
    protected $signature = 'sms:repair-pending-orphans
                            {--execute : Actually push to mNotify (default: dry run)}
                            {--source= : Limit to a source type (reminder|birthday)}
                            {--date= : Limit to a send date (Y-m-d)}
                            {--limit=0 : Maximum rows to repair (0 = no limit)}';

    protected $description = 'Re-push pending_api SMS deliveries that never received an mNotify job ID';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $limit = (int) $this->option('limit');

        $this->line('=== ORPHANED PENDING SMS REPAIR ===');
        $this->line('Mode: '.($execute ? 'EXECUTE (will push to mNotify)' : 'DRY RUN (use --execute to apply)'));
        $this->line('');

        $query = ScheduledSmsDelivery::query()
            ->where('status', ScheduledSmsDelivery::STATUS_PENDING_API)
            ->where(fn ($q) => $q->whereNull('mnotify_job_id')->orWhere('mnotify_job_id', ''));

        if ($source = $this->option('source')) {
            $query->where('source_type', $source);
        }

        if ($date = $this->option('date')) {
            $query->whereDate('scheduled_at', $date);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $orphans = $query->orderBy('scheduled_at')->get();

        $this->line("Orphaned pending deliveries: {$orphans->count()}");

        if ($orphans->isEmpty()) {
            $this->info('Nothing to repair — every pending delivery carries an mNotify job ID.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('=== BROKEN DOWN BY SLOT ===');

        foreach ($orphans->groupBy(fn ($d) => $d->source_type.' @ '.$d->scheduled_at->format('Y-m-d H:i')) as $slot => $rows) {
            $this->line(sprintf('  %-42s %3d row(s)', $slot, $rows->count()));
        }

        // Already queued for retry — the offline drain owns these.
        $alreadyQueued = PendingRemoteSchedule::query()
            ->where('action', PendingRemoteSchedule::ACTION_SCHEDULE)
            ->whereIn('status', ['pending', 'processing'])
            ->pluck('scheduled_sms_delivery_id')
            ->flip();

        $skipped = $orphans->whereIn('id', $alreadyQueued->keys()->all());
        $todo = $orphans->whereNotIn('id', $alreadyQueued->keys()->all());

        if ($skipped->isNotEmpty()) {
            $this->line('');
            $this->line("  {$skipped->count()} already in the offline retry queue — sync:pending-schedules will drain them.");
        }

        $this->line("  {$todo->count()} to push now.");

        if (! $execute) {
            $this->comment('Dry run. Re-run with --execute to push these to mNotify.');

            return self::SUCCESS;
        }

        $pushed = 0;
        $failed = 0;

        $this->line('');
        $this->line('=== PUSHING ===');

        foreach ($todo as $delivery) {
            try {
                DispatchScheduledSmsToMnotifyJob::dispatchSync($delivery->id);
                $delivery->refresh();

                if ($delivery->status === ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE) {
                    $pushed++;
                    $this->line(sprintf('  %-12s %s  -> job %s', $delivery->source_type, $delivery->phone, $delivery->mnotify_job_id));

                    continue;
                }

                $failed++;
                $this->line(sprintf('  %-12s %s  -> %s (%s)', $delivery->source_type, $delivery->phone, $delivery->status, $delivery->error_message));
            } catch (\Throwable $e) {
                // Never let one recipient stop the repair run.
                $failed++;
                $this->line(sprintf('  %-12s %s  -> ERROR %s', $delivery->source_type, $delivery->phone, $e->getMessage()));
                Log::warning('sms:repair-pending-orphans push failed', [
                    'delivery_id' => $delivery->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->line("Pushed: {$pushed}   Not scheduled: {$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
