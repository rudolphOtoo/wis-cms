<?php

namespace App\Observers;

use App\Models\BirthdayMessageSettings;
use App\Services\RecurringSmsScheduler;

/**
 * Configure-time sync for birthday-greeting automations.
 *
 * When a branch's birthday settings are edited while active, every
 * future birthday delivery for that branch is immediately rescheduled
 * on mNotify (old messages cancelled, fresh ones pushed) so the remote
 * scheduled-messages list always matches the latest template without
 * waiting for the daily rolling sync.
 *
 * Deactivating the automation cancels all future birthday deliveries
 * on mNotify; reactivating regenerates the forward window.
 *
 * Note: this observer intentionally has no `created()` hook. The
 * per-branch settings row is auto-created (with is_active = true) on
 * first VIEW via BirthdayMessageSettings::forBranch(), which must not
 * trigger a push — only an explicit admin save through
 * BirthdayController::updateSettings() fires `updated`.
 */
class BirthdayMessageSettingsObserver
{
    public function updated(BirthdayMessageSettings $settings): void
    {
        if ($settings->wasChanged('is_active')) {
            $wasActive = $settings->getOriginal('is_active');

            if (! $settings->is_active && $wasActive) {
                $this->scheduler()->cancelBirthdayDeliveries($settings->branch_id, 'deactivated');

                return;
            }

            if ($settings->is_active && ! $wasActive) {
                $this->scheduler()->resyncBirthdaySettings($settings);

                return;
            }
        }

        if ($settings->is_active && $settings->wasChanged('template')) {
            $this->scheduler()->resyncBirthdaySettings($settings);
        }
    }

    protected function scheduler(): RecurringSmsScheduler
    {
        return app(RecurringSmsScheduler::class);
    }
}
