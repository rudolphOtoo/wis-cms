<?php

namespace App\Console\Commands;

use App\Exceptions\TransientSmsException;
use App\Models\ScheduledSmsDelivery;
use App\Services\MnotifySmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeAllMnotifySchedules extends Command
{
    protected $signature = 'sms:purge-all-mnotify-schedules {--execute : Actually cancel remote jobs (default: dry run)}';

    protected $description = 'Purge all pending remote schedules from mNotify';

    public function handle(MnotifySmsService $sms): int
    {
        $execute = (bool) $this->option('execute');

        $deliveries = ScheduledSmsDelivery::query()
            ->where('status', ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE)
            ->whereNotNull('mnotify_job_id')
            ->get();

        $this->info('Found '.$deliveries->count().' local scheduled_remote deliveries with mnotify_job_id.');

        if (! $execute) {
            $this->warn('Dry run. Pass --execute to actually cancel them on mNotify and mark them cancelled.');
            foreach ($deliveries as $delivery) {
                $this->line("  [DRY] Would cancel mNotify job {$delivery->mnotify_job_id} for {$delivery->phone} at {$delivery->scheduled_at}");
            }

            return self::SUCCESS;
        }

        $cancelled = 0;
        $failed = 0;

        foreach ($deliveries as $delivery) {
            try {
                $result = $sms->deleteScheduledJob($delivery->mnotify_job_id);
                if ($result['confirmed'] ?? false) {
                    $delivery->update([
                        'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
                        'error_message' => trim(($delivery->error_message ? $delivery->error_message."\n" : '').'Purged by manual administrative purge'),
                    ]);
                    $cancelled++;
                    Log::info("Purged mNotify job {$delivery->mnotify_job_id} for delivery {$delivery->id} via sms:purge-all-mnotify-schedules");
                } else {
                    // Fallback: defuse by moving to 2099-12-31
                    try {
                        $updated = $sms->updateScheduled(
                            $delivery->mnotify_job_id,
                            $delivery->phone,
                            '(canceled) '.$delivery->message_body,
                            now()->addYears(73)
                        );
                        if ($updated) {
                            $delivery->update([
                                'status' => ScheduledSmsDelivery::STATUS_CANCELLED,
                                'error_message' => trim(($delivery->error_message ? $delivery->error_message."\n" : '').'Purged by manual administrative purge'),
                            ]);
                            $cancelled++;

                            continue;
                        }
                    } catch (\Throwable) {
                    }
                    $failed++;
                    $this->error("Failed to cancel mNotify job {$delivery->mnotify_job_id} for delivery {$delivery->id}");
                }
            } catch (TransientSmsException $e) {
                $this->error("Transient error cancelling {$delivery->mnotify_job_id}: ".$e->getMessage());
                $failed++;
            } catch (\Throwable $e) {
                $this->error("Error cancelling {$delivery->mnotify_job_id}: ".$e->getMessage());
                $failed++;
            }
        }

        $this->info("Cancelled: {$cancelled}, Failed: {$failed}");

        return self::SUCCESS;
    }
}
