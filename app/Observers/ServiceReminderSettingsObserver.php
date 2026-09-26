<?php

namespace App\Observers;

use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use App\Services\RecurringSmsScheduler;
use Illuminate\Support\Facades\Log;

/**
 * Automated lifecycle guard for service reminder SMS.
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
 * Configure-time sync: whenever a reminder is created, or its
 * schedule/template is edited while active, the whole forward window
 * is rescheduled on mNotify immediately (old messages cancelled,
 * new ones pushed) so the remote scheduled-messages list always
 * matches the latest configuration without waiting for the daily
 * rolling sync.
 *
 * Each cancellation runs through CancelScheduledSmsJob so transient
 * network failures are retried via the pending_remote_schedules queue,
 * keeping remote cleanup resilient to outages.
 */
class ServiceReminderSettingsObserver
{
    /**
     * Fields that, when changed on an active automation, require a full
     * resync of the scheduled messages (body or fire time changed).
     */
    private const RESYNC_FIELDS = [
        'template',
        'send_day_of_week',
        'send_hour',
        'service_hour',
        'service_minute',
    ];

    public function created(ServiceReminderSettings $settings): void
    {
        if ($settings->is_active) {
            $this->scheduler()->resyncServiceReminder($settings);
        }
    }

    public function updated(ServiceReminderSettings $settings): void
    {
        if (! $settings->wasChanged('is_active')) {
            if ($settings->is_active && $settings->wasChanged(self::RESYNC_FIELDS)) {
                $this->scheduler()->resyncServiceReminder($settings);
            }

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
        // Routed through the scheduler so each cancellation issues a real
        // DELETE /scheduled/{id} against mNotify and a single unreachable
        // recipient cannot abort the remaining cancellations mid-loop.
        $cancelled = $this->scheduler()->cancelFutureDeliveries(
            ScheduledSmsDelivery::query()
                ->where('source_type', 'reminder')
                ->where('source_id', $settings->id),
            $reason,
        );

        Log::info('Service reminder deactivated — remote cancellations dispatched', [
            'settings_id' => $settings->id,
            'reason' => $reason,
            'cancelled_deliveries' => $cancelled,
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

            $this->scheduler()->pushDeliveryToMnotify($delivery);
        }

        Log::info('Service reminder reactivated — resuming remote schedules', [
            'settings_id' => $settings->id,
            'resumed_deliveries' => $deliveries->count(),
        ]);
    }

    protected function scheduler(): RecurringSmsScheduler
    {
        return app(RecurringSmsScheduler::class);
    }
}
