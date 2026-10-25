<?php

namespace App\Console\Commands;

use App\Exceptions\TransientSmsException;
use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\PendingRemoteSchedule;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recover a day whose local deliveries claim to be scheduled remotely but
 * whose mNotify jobs no longer exist.
 *
 * The admin UI reads its "will send even if this PC is off" badge from the
 * local ledger, not from mNotify. A failed cancellation or a provider-side
 * purge can therefore leave rows marked scheduled_remote while the cloud
 * holds nothing for that date. Repairing those rows through the settings
 * screen is unsafe: the old rows can win the recipient-day duplicate guard
 * and cancel the replacement.
 *
 * This command is deliberately date-scoped and refuses to run when mNotify
 * already holds any future, non-defused job for that date. That makes the
 * operation impossible to use as a duplicate generator: it only requeues
 * rows when the cloud has nothing that could collide with them.
 */
class RequeueSmsDay extends Command
{
    protected $signature = 'sms:requeue-day
                            {date : The send date to requeue (Y-m-d)}
                            {--execute : Reset the stale rows and push them to mNotify (default: dry run)}
                            {--source= : Limit to a source type (reminder or birthday)}';

    protected $description = 'Requeue stale scheduled_remote deliveries for one date when mNotify holds no jobs for it';

    public function handle(MnotifySmsService $sms): int
    {
        $date = (string) $this->argument('date');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Invalid date. Use Y-m-d, for example 2026-10-28.');

            return self::FAILURE;
        }

        $start = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        $end = $start->copy()->addDay();

        if ($end->lessThanOrEqualTo(now())) {
            $this->error("{$date} is in the past. This command only repairs future deliveries.");

            return self::FAILURE;
        }

        $source = $this->option('source');

        if ($source !== null && ! in_array($source, ['reminder', 'birthday'], true)) {
            $this->error('--source must be reminder or birthday.');

            return self::FAILURE;
        }

        $this->line('=== SMS DAY REQUEUE ===');
        $this->line('Date: '.$start->toDateString());
        $this->line('Mode: '.($this->option('execute') ? 'EXECUTE (reset and push)' : 'DRY RUN (use --execute to apply)'));
        $this->line('');

        try {
            $remote = collect($sms->fetchScheduledJobs());
        } catch (TransientSmsException $e) {
            $this->error('Could not read the mNotify schedule. Aborting — nothing was changed.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $remoteForDate = $this->remoteJobsOnDate($sms, $remote, $start);

        $this->line('mNotify active jobs on this date: '.$remoteForDate->count());

        if ($remoteForDate->isNotEmpty()) {
            $this->error('Refusing to requeue: mNotify already holds '.$remoteForDate->count()
                .' active job(s) for '.$start->toDateString().'.');
            $this->line('This guard prevents duplicate SMS. Inspect the cloud first:');
            $this->line('  php artisan mnotify:purge-cloud-duplicates --date='.$start->toDateString());

            return self::FAILURE;
        }

        $query = ScheduledSmsDelivery::query()
            ->where('scheduled_at', '>=', $start)
            ->where('scheduled_at', '<', $end)
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_PENDING_API,
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            ]);

        if ($source !== null) {
            $query->where('source_type', $source);
        }

        $deliveries = $query->orderBy('scheduled_at')->orderBy('phone')->get();

        $this->line('Local active deliveries on this date: '.$deliveries->count());

        if ($deliveries->isEmpty()) {
            $this->warn('No local queued deliveries found for this date.');
            $this->line('The reminder screen\'s "Fires Wednesday at ..." text is the automation setting,');
            $this->line('not proof of an individual queued message. Edit/save the reminder to generate it.');
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        foreach ($deliveries->groupBy(fn (ScheduledSmsDelivery $d) => $d->status.' / '.$d->source_type) as $slot => $rows) {
            $this->line(sprintf('  %-32s %d', $slot, $rows->count()));
        }

        if (! $this->option('execute')) {
            $this->line('');
            $this->comment('Dry run. '.$deliveries->count().' local row(s) would be reset to pending_api and pushed.');
            $this->comment('They will only be pushed because mNotify has zero deliverable jobs for this date.');
            $this->comment('Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        $ids = $deliveries->pluck('id')->all();

        [$queueCleared, $reset] = DB::transaction(function () use ($ids, $start) {
            $queueCleared = PendingRemoteSchedule::query()
                ->whereIn('scheduled_sms_delivery_id', $ids)
                ->whereIn('status', [
                    PendingRemoteSchedule::STATUS_PENDING,
                    PendingRemoteSchedule::STATUS_PROCESSING,
                ])
                ->update([
                    'status' => PendingRemoteSchedule::STATUS_COMPLETED,
                    'error_message' => 'Superseded by sms:requeue-day '.$start->toDateString(),
                    'updated_at' => now(),
                ]);

            $reset = ScheduledSmsDelivery::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
                    'mnotify_job_id' => null,
                    'error_message' => 'Requeued by sms:requeue-day: mNotify held no job for this date',
                    'updated_at' => now(),
                ]);

            return [$queueCleared, $reset];
        });

        $this->line('');
        $this->line("Cleared old retry items: {$queueCleared}");
        $this->line("Reset local deliveries:  {$reset}");
        $this->line('');
        $this->line('=== PUSHING ===');

        $pushed = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                DispatchScheduledSmsToMnotifyJob::dispatchSync($id);

                $delivery = ScheduledSmsDelivery::find($id);

                if ($delivery && $delivery->status === ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE) {
                    $pushed++;
                    $this->line(sprintf('  %-12s %s  -> job %s', $delivery->source_type, $delivery->phone, $delivery->mnotify_job_id));

                    continue;
                }

                $failed++;
                $this->line(sprintf('  %-12s %s  -> %s (%s)', $delivery?->source_type, $delivery?->phone, $delivery?->status, $delivery?->error_message));
            } catch (\Throwable $e) {
                $failed++;
                $this->line('  ERROR '.$e->getMessage());
                Log::warning('sms:requeue-day push failed', [
                    'delivery_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->line("Pushed: {$pushed}");
        $this->line("Not scheduled: {$failed}");

        try {
            $after = $this->remoteJobsOnDate($sms, $sms->refreshScheduledJobs(), $start)->count();

            $this->line("mNotify now holds {$after} active job(s) for ".$start->toDateString().'.');

            if ($after !== $pushed) {
                $this->warn('Cloud count differs from the number pushed. Run mnotify:purge-cloud-duplicates --date='
                    .$start->toDateString().' before switching the machine off.');
            }
        } catch (TransientSmsException $e) {
            $this->warn('Pushed, but could not re-read the cloud schedule: '.$e->getMessage());
        }

        Log::info('sms:requeue-day executed', [
            'date' => $start->toDateString(),
            'queue_cleared' => $queueCleared,
            'reset' => $reset,
            'pushed' => $pushed,
            'failed' => $failed,
        ]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every future, non-defused cloud job on $day, irrespective of the
     * provider's status label. Treating an unknown status as active is
     * deliberate: this check exists to prevent duplicates, so the
     * conservative answer is the safe one.
     *
     * @param  iterable<array<string, mixed>>  $jobs
     * @return Collection<int, array<string, mixed>>
     */
    protected function remoteJobsOnDate(MnotifySmsService $sms, iterable $jobs, Carbon $day): Collection
    {
        return collect($jobs)
            ->filter(function (array $job) use ($sms, $day) {
                if ($sms->isDefusedScheduledJob($job) || ! $sms->isFutureScheduledJob($job)) {
                    return false;
                }

                try {
                    return Carbon::parse((string) ($job['date_time'] ?? ''))->isSameDay($day);
                } catch (\Throwable) {
                    return false;
                }
            })
            ->values();
    }
}
