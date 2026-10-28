<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\TransientSmsException;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Illuminate\Console\Command;

/**
 * Read-only verification that the mNotify cloud queue is clear.
 *
 * "Pending" means the provider still holds a job that has not reached a
 * terminal sent/failed state. Defused year-2099 jobs and past-dated stuck
 * jobs are counted as pending too, because they still occupy the remote
 * schedule; use --deliverable-only when only future real messages matter.
 */
class CheckMnotifyStatus extends Command
{
    protected $signature = 'sms:check-mnotify-status
                            {--json : Print a machine-readable summary}
                            {--deliverable-only : Exit 0 when only defused/stale pending jobs remain}';

    protected $description = 'Check how many pending scheduled SMS jobs remain on mNotify';

    public function handle(MnotifySmsService $sms): int
    {
        try {
            $jobs = $sms->fetchScheduledJobs();
        } catch (TransientSmsException $e) {
            $this->error('Could not read the mNotify schedule: '.$e->getMessage());

            return self::FAILURE;
        }

        $pending = 0;
        $deliverable = 0;
        $defused = 0;
        $stale = 0;
        $terminal = 0;

        foreach ($jobs as $job) {
            if (! $sms->isPendingScheduledJob($job)) {
                $terminal++;

                continue;
            }

            $pending++;

            if ($sms->isDefusedScheduledJob($job)) {
                $defused++;
            } elseif ($sms->isFutureScheduledJob($job)) {
                $deliverable++;
            } else {
                $stale++;
            }
        }

        $localPending = ScheduledSmsDelivery::query()
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_PENDING_API,
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            ])
            ->where('scheduled_at', '>=', now())
            ->count();

        $clean = $this->option('deliverable-only')
            ? $deliverable === 0
            : $pending === 0;

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'clean' => $clean,
                'remote_total' => count($jobs),
                'pending' => $pending,
                'deliverable' => $deliverable,
                'defused' => $defused,
                'stale' => $stale,
                'terminal' => $terminal,
                'local_pending_future' => $localPending,
            ], JSON_PRETTY_PRINT));

            return $clean ? self::SUCCESS : self::FAILURE;
        }

        $this->line('mNotify cloud scheduled jobs : '.count($jobs));
        $this->line('Pending scheduled messages    : '.$pending);
        $this->line('  Deliverable (future)        : '.$deliverable);
        $this->line('  Defused (2099)              : '.$defused);
        $this->line('  Stale/past-dated            : '.$stale);
        $this->line('Terminal history              : '.$terminal);
        $this->line('Local pending future rows     : '.$localPending);

        $this->newLine();

        if ($clean) {
            $this->info('CLEAN — 0 pending scheduled messages remain on mNotify.');

            return self::SUCCESS;
        }

        $this->warn("NOT CLEAN — {$pending} pending scheduled message(s) remain on mNotify.");

        if ($deliverable > 0) {
            $this->warn("{$deliverable} of them can still deliver a real message. Run sms:purge-mnotify-cloud.");
        } else {
            $this->line('None are deliverable (all defused or past-dated), but they still occupy the remote schedule.');
        }

        return self::FAILURE;
    }
}
