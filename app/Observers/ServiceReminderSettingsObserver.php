<?php

namespace App\Observers;

use App\Jobs\CancelScheduledSmsJob;
use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use Illuminate\Support\Facades\Log;

/**
 * Automated deactivation guard for service reminder SMS.
 *
 * Whenever a reminder setting is deactivated or deleted in the admin
 * panel, every pending / remote-scheduled SMS linked to it must be
 * cancelled on mNotify's servers — otherwise members keep receiving
 * "ghost" reminders from an automation that no longer exists.
 *
 * Conversely, reactivating a setting resumes any future deliveries
 * that were cancelled during the off period, so switching an
 * automation back on continues its upcoming schedule instead of
 * leaving dead tombstones behind.
 *
 * Each cancellation runs through CancelScheduledSmsJob so transient
 * network failures are retried via the pending_remote_schedules queue,
 * keeping remote cleanup resilient to outages.
 */
class ServiceReminderSettingsObserver
{
    public function updated(ServiceReminderSettings $settings): void
    {
        if (! $settings->wasChanged('is_active')) {
            return;
        }

        $wasActive = $settings->getOriginal('is_active');

        if (! $settings->is_active && $wasActive) {
            $this->cancelAssociatedDeliveries($settings, 'deactivated');
        }

        if ($settings->is_active && ! $wasActive) {
            $this->resumeFutureDeliveries($settings);
        }
    }

    public function deleted(ServiceReminderSettings $settings): void
    {
        $this->cancelAssociatedDeliveries($settings, 'deleted');
    }

    protected function cancelAssociatedDeliveries(ServiceReminderSettings $settings, string $reason): void
    {
        $deliveries = ScheduledSmsDelivery::forSource('reminder', $settings->id)
            ->active()
            ->where('scheduled_at', '>=', now())
            ->get();

        foreach ($deliveries as $delivery) {
            CancelScheduledSmsJob::dispatch($delivery->id);
        }

        Log::info('Service reminder deactivated — dispatching remote cancellations', [
            'settings_id' => $settings->id,
            'reason' => $reason,
            'cancelled_deliveries' => $deliveries->count(),
        ]);
    }

    /**
     * Re-arm future deliveries that were cancelled or defused while
     * the automation was switched off. Rows are reset to pending_api
     * and re-pushed to mNotify, so reactivation genuinely resumes the
     * upcoming schedule rather than silently skipping those dates.
     */
    protected function resumeFutureDeliveries(ServiceReminderSettings $settings): void
    {
        $deliveries = ScheduledSmsDelivery::forSource('reminder', $settings->id)
            ->whereIn('status', [
                ScheduledSmsDelivery::STATUS_CANCELLED,
                ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
            ])
            ->where('scheduled_at', '>=', now())
            ->get();

        foreach ($deliveries as $delivery) {
            $delivery->update([
                'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
                'error_message' => 'Resumed: reminder automation was reactivated',
            ]);

            DispatchScheduledSmsToMnotifyJob::dispatch($delivery->id);
        }

        Log::info('Service reminder reactivated — resuming remote schedules', [
            'settings_id' => $settings->id,
            'resumed_deliveries' => $deliveries->count(),
        ]);
    }
}
