<?php

namespace App\Console\Commands;

use App\Models\ScheduledSmsDelivery;
use Illuminate\Console\Command;

/**
 * Canonical audit of the scheduled SMS pipeline.
 *
 * Single source of truth for pre-shutdown verification: reports every
 * future delivery grouped by date/status, verifies each record carries
 * a remote mNotify job ID, and breaks results down by source
 * (reminder / birthday) so orphaned batches are immediately visible.
 *
 * Replaces ad-hoc tinker one-liners (which historically referenced a
 * non-existent `message` column — the real column is `message_body`).
 */
class AuditScheduledSms extends Command
{
    protected $signature = 'sms:audit-schedules';

    protected $description = 'Audit scheduled SMS deliveries: statuses, remote job IDs, and upcoming batches';

    public function handle(): int
    {
        $future = ScheduledSmsDelivery::query()
            ->where('scheduled_at', '>=', now());

        // ─── Status breakdown ──────────────────────────────────────
        $this->line('=== STATUS BREAKDOWN (future deliveries) ===');
        $byStatus = (clone $future)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        if ($byStatus->isEmpty()) {
            $this->info('No future deliveries scheduled.');
        }
        foreach ($byStatus as $status => $cnt) {
            $this->line(sprintf('  %-20s %d', $status, $cnt));
        }

        // ─── Batches by send time x source ─────────────────────────
        $this->line('');
        $this->line('=== UPCOMING BATCHES ===');
        $batches = ScheduledSmsDelivery::query()
            ->where('scheduled_at', '>=', now())
            ->selectRaw('min(scheduled_at) as earliest, scheduled_at::date as day, source_type, status, count(*) as cnt')
            ->groupBy('day', 'source_type', 'status')
            ->orderBy('earliest')
            ->get();

        foreach ($batches as $batch) {
            $this->line(sprintf(
                '  %s | %-9s | %-16s | %d message(s)',
                $batch->day,
                $batch->source_type,
                $batch->status,
                $batch->cnt,
            ));
        }

        // ─── Remote job ID integrity ───────────────────────────────
        $missingJobIds = (clone $future)
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_PENDING_API,
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            ])
            ->where(fn ($w) => $w->whereNull('mnotify_job_id')->orWhere('mnotify_job_id', ''))
            ->count();

        $this->line('');
        $this->line('=== REMOTE JOB ID INTEGRITY ===');
        $this->line($missingJobIds === 0
            ? '  OK — every pending/scheduled delivery carries an mNotify job ID.'
            : "  WARNING — {$missingJobIds} delivery(ies) missing mnotify_job_id. Run sync:pending-schedules.");

        // ─── Cancelled remote jobs ─────────────────────────────────
        $cancelledRemote = ScheduledSmsDelivery::cancelledRemote()->count();
        $this->line('');
        $this->line('=== CANCELLED REMOTE JOBS ===');
        $this->line("  Total cancelled_remote jobs: {$cancelledRemote}");

        return self::SUCCESS;
    }
}
