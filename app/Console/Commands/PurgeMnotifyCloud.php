<?php

namespace App\Console\Commands;

use App\Exceptions\TransientSmsException;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-time operational purge for mNotify's remote scheduled-SMS queue.
 *
 * Unlike sms:purge-all-mnotify-schedules (local-ledger driven), this reads
 * the provider's authoritative GET /scheduled list, so it also removes
 * orphan/duplicate jobs the local database no longer knows about. Every
 * pending job is DELETE'd; if mNotify's DELETE endpoint is down, the job
 * is defused to 2099 instead so it can never deliver.
 */
class PurgeMnotifyCloud extends Command
{
    protected $signature = 'sms:purge-mnotify-cloud
                            {--dry-run : List the pending jobs without deleting anything}
                            {--keep-defused : Leave previously defused year-2099 jobs alone}';

    protected $description = 'Delete every pending scheduled SMS job from the mNotify cloud';

    public function handle(MnotifySmsService $sms): int
    {
        try {
            $jobs = $sms->refreshScheduledJobs();
        } catch (TransientSmsException $e) {
            $this->error('Could not read the mNotify schedule: '.$e->getMessage());
            Log::error('sms:purge-mnotify-cloud: schedule listing unavailable', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $keepDefused = (bool) $this->option('keep-defused');
        $candidates = array_values(array_filter($jobs, function (array $job) use ($sms, $keepDefused): bool {
            if (! $sms->isPendingScheduledJob($job)) {
                return false;
            }

            return ! ($keepDefused && $sms->isDefusedScheduledJob($job));
        }));

        $this->line('Remote scheduled jobs: '.count($jobs));
        $this->line('Pending jobs to purge : '.count($candidates));

        if ($candidates === []) {
            $this->info('Nothing to purge — the mNotify schedule has no pending jobs.');

            return self::SUCCESS;
        }

        $this->table(
            ['mNotify ID', 'Scheduled for', 'Defused', 'Message'],
            array_map(fn (array $job): array => [
                (string) ($job['_id'] ?? '?'),
                (string) ($job['date_time'] ?? '?'),
                $sms->isDefusedScheduledJob($job) ? 'yes' : 'no',
                str($job['message'] ?? '')->replace("\n", ' ')->limit(60)->toString(),
            ], $candidates),
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry run only. Re-run without --dry-run to delete these jobs.');

            return self::SUCCESS;
        }

        $deleted = [];
        $defused = [];
        $failed = [];
        $unidentified = 0;

        foreach ($candidates as $job) {
            $jobId = trim((string) ($job['_id'] ?? ''));

            if ($jobId === '') {
                $unidentified++;
                $this->warn('Skipped a pending job with no mNotify _id.');

                continue;
            }

            $label = "#{$jobId} ({$job['date_time']})";

            try {
                $result = $sms->deleteScheduledJob($jobId);

                if ($result['confirmed']) {
                    $deleted[$jobId] = true;
                    $this->line("[deleted] {$label}");
                    Log::info('sms:purge-mnotify-cloud: deleted remote schedule', [
                        'mnotify_job_id' => $jobId,
                        'date_time' => $job['date_time'] ?? null,
                    ]);

                    continue;
                }

                $this->warn("[DELETE unconfirmed] {$label} — HTTP {$result['http_status']}. Defusing instead.");
            } catch (TransientSmsException $e) {
                $this->warn("[DELETE failed] {$label} — {$e->getMessage()}. Defusing instead.");
                Log::warning('sms:purge-mnotify-cloud: DELETE failed, attempting defuse', [
                    'mnotify_job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $result = $sms->defuseScheduledJob($jobId);

                if ($result['confirmed']) {
                    $defused[$jobId] = true;
                    $this->warn("[defused] {$label} -> 2099-12-31");
                    Log::warning('sms:purge-mnotify-cloud: defused remote schedule after failed delete', [
                        'mnotify_job_id' => $jobId,
                        'date_time' => $job['date_time'] ?? null,
                    ]);

                    continue;
                }

                $failed[] = $jobId;
                $this->error("[failed] {$label} — DELETE and defuse both rejected (HTTP {$result['http_status']}).");
            } catch (TransientSmsException $e) {
                $failed[] = $jobId;
                $this->error("[failed] {$label} — DELETE and defuse both failed: {$e->getMessage()}");
            }
        }

        $withdrawnIds = array_keys($deleted + $defused);
        $localUpdated = $this->markLocalRowsWithdrawn($withdrawnIds);

        $this->newLine();
        $this->line('Deleted : '.count($deleted));
        $this->line('Defused : '.count($defused));
        $this->line('Failed  : '.count($failed));
        $this->line('Local ledger rows updated: '.$localUpdated);

        if ($unidentified > 0) {
            $this->warn("Unidentified pending jobs skipped: {$unidentified}");
        }

        try {
            $remaining = array_filter(
                $sms->refreshScheduledJobs(),
                fn (array $job): bool => $sms->isPendingScheduledJob($job),
            );

            $this->line('Pending jobs remaining : '.count($remaining));

            if ($remaining !== []) {
                $this->warn('The cloud still reports pending jobs. Run sms:check-mnotify-status for details.');
            }
        } catch (TransientSmsException $e) {
            $this->warn('Could not re-read the mNotify schedule after purging: '.$e->getMessage());
        }

        if ($failed !== [] || $unidentified > 0) {
            Log::error('sms:purge-mnotify-cloud: incomplete purge', [
                'deleted' => array_keys($deleted),
                'defused' => array_keys($defused),
                'failed' => $failed,
                'unidentified' => $unidentified,
            ]);

            return self::FAILURE;
        }

        $this->info('Purge complete.');

        return self::SUCCESS;
    }

    /**
     * Mark local rows whose remote job we just withdrew as cancelled.
     *
     * Matching on mnotify_job_id is intentionally conservative: mNotify's
     * create response is not always the same identifier used by
     * GET/DELETE, so a row with a mismatched ID is left for the normal
     * reconciliation tooling.
     *
     * @param  list<string>  $jobIds
     */
    protected function markLocalRowsWithdrawn(array $jobIds): int
    {
        if ($jobIds === []) {
            return 0;
        }

        return ScheduledSmsDelivery::query()
            ->whereIn('mnotify_job_id', $jobIds)
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_PENDING_API,
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
            ])
            ->update([
                'status' => ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
                'error_message' => 'Withdrawn by sms:purge-mnotify-cloud',
                'updated_at' => now(),
            ]);
    }
}
