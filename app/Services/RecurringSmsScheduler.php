<?php

namespace App\Services;

use App\Jobs\CancelScheduledSmsJob;
use App\Jobs\DispatchScheduledSmsToMnotifyJob;
use App\Models\BirthdayMessageSettings;
use App\Models\Member;
use App\Models\ScheduledSmsDelivery;
use App\Models\ServiceReminderSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for recurring SMS automation scheduling.
 *
 * Two entry points share the same generation logic:
 *
 *   1. Batch sync (sms:sync-rolling-automations) collects deliveries for
 *      ALL active automations across the next N days when the admin never
 *      explicitly re-configured anything. It honors cancelled rows as
 *      tombstones so a re-run never resurrects a deliberately cancelled
 *      message.
 *
 *   2. Configure-time resync (driven by the setting observers) runs
 *      whenever an automation is created or edited while active. It
 *      cancels the automation's currently scheduled messages on mNotify
 *      and re-creates them from the latest template/schedule, so the
 *      remote scheduled-messages list always mirrors the configuration —
 *      without waiting for the 05:00 batch run.
 *
 * Both paths dispatch the same queued jobs (DispatchScheduledSmsToMnotifyJob
 * for pushes, CancelScheduledSmsJob for cancellations), preserving the
 * existing offline-resilience guarantees (PendingRemoteSchedule retries).
 */
class RecurringSmsScheduler
{
    /**
     * Default forward window when no explicit day count is given.
     * Mirrors the --days default of sms:sync-rolling-automations.
     */
    public const DEFAULT_WINDOW_DAYS = 14;

    // ─── Batch collection (command: sms:sync-rolling-automations) ───

    /**
     * Collect birthday-greeting deliveries for the next $days days across
     * all branches. Creates pending_api rows but does NOT dispatch jobs —
     * the caller dispatches after its credit guard.
     *
     * Returns the created delivery IDs.
     */
    public function collectBirthdayDeliveries(int $days): array
    {
        if (! config('church.birthday.enabled')) {
            return [];
        }

        $ids = [];
        $today = now()->startOfDay();
        $churchName = config('church.name', 'Wesleyan International Society');

        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $scheduledAt = $date->copy()->hour(7)->minute(0)->second(0);

            if ($scheduledAt->isPast()) {
                continue;
            }

            $members = Member::eligibleForSms()
                ->whereNotNull('date_of_birth')
                ->whereRaw('EXTRACT(MONTH FROM date_of_birth) = ?', [$date->month])
                ->whereRaw('EXTRACT(DAY FROM date_of_birth) = ?', [$date->day])
                ->get();

            foreach ($members as $member) {
                if ($this->isAlreadyScheduled('birthday', $member->id, null, $scheduledAt)) {
                    continue;
                }

                $settings = BirthdayMessageSettings::forBranch($member->branch_id);

                if (! $settings->is_active) {
                    continue;
                }

                $body = $settings->render($member, $churchName);

                $delivery = ScheduledSmsDelivery::create([
                    'branch_id' => $member->branch_id,
                    'phone' => $member->phone,
                    'message_body' => $body,
                    'scheduled_at' => $scheduledAt,
                    'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
                    'source_type' => 'birthday',
                    'source_id' => $member->id,
                ]);

                $ids[] = $delivery->id;
            }
        }

        return $ids;
    }

    /**
     * Collect service-reminder deliveries for the next $days days across
     * all active settings. Creates pending_api rows but does NOT dispatch
     * jobs — the caller dispatches after its credit guard.
     *
     * Returns the created delivery IDs.
     */
    public function collectServiceReminderDeliveries(int $days): array
    {
        $ids = [];
        $today = now()->startOfDay();

        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $dow = $date->dayOfWeek;

            $settings = ServiceReminderSettings::query()
                ->with(['serviceType', 'branch'])
                ->where('is_active', true)
                ->where('send_day_of_week', $dow)
                ->get();

            foreach ($settings as $setting) {
                $ids = array_merge($ids, $this->collectReminderForSettings($setting, $date, []));
            }
        }

        return $ids;
    }

    // ─── Configure-time resync (observers) ─────────────────────────

    /**
     * Resync an active service-reminder automation after it was created
     * or edited. Cancels every currently scheduled future message for
     * that source on mNotify, then regenerates the whole forward window
     * from the latest template/schedule and pushes it immediately.
     *
     * Returns the number of deliveries (re)created.
     */
    public function resyncServiceReminder(ServiceReminderSettings $settings, ?int $days = null): int
    {
        $days = $this->windowDays($days);

        $deprecatedIds = $this->deprecateFutureDeliveries(
            ScheduledSmsDelivery::query()
                ->where('source_type', 'reminder')
                ->where('source_id', $settings->id)
        );

        $count = 0;
        $today = now()->startOfDay();

        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);

            if ($date->dayOfWeek !== $settings->send_day_of_week) {
                continue;
            }

            $ids = $this->collectReminderForSettings($settings, $date, $deprecatedIds);

            foreach ($ids as $id) {
                $this->dispatchSchedule($id);
            }

            $count += count($ids);
        }

        $this->logSync('Service reminder reconfigured — rescheduled on mNotify', [
            'settings_id' => $settings->id,
            'branch_id' => $settings->branch_id,
            'deprecated' => count($deprecatedIds),
            'created' => $count,
        ]);

        return $count;
    }

    /**
     * Resync an active birthday automation after its settings were edited.
     * Cancels all future birthday deliveries for the branch, then
     * regenerates the forward window from the current template and pushes
     * it immediately.
     *
     * Returns the number of deliveries (re)created.
     */
    public function resyncBirthdaySettings(BirthdayMessageSettings $settings, ?int $days = null): int
    {
        if (! config('church.birthday.enabled') || ! $settings->is_active) {
            return 0;
        }

        $days = $this->windowDays($days);

        $deprecatedIds = $this->deprecateFutureDeliveries(
            ScheduledSmsDelivery::query()
                ->where('source_type', 'birthday')
                ->where('branch_id', $settings->branch_id)
        );

        $count = 0;
        $today = now()->startOfDay();
        $churchName = config('church.name', 'Wesleyan International Society');

        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $scheduledAt = $date->copy()->hour(7)->minute(0)->second(0);

            if ($scheduledAt->isPast()) {
                continue;
            }

            $members = Member::eligibleForSms()
                ->where('branch_id', $settings->branch_id)
                ->whereNotNull('date_of_birth')
                ->whereRaw('EXTRACT(MONTH FROM date_of_birth) = ?', [$date->month])
                ->whereRaw('EXTRACT(DAY FROM date_of_birth) = ?', [$date->day])
                ->get();

            foreach ($members as $member) {
                if ($this->isAlreadyScheduled('birthday', $member->id, null, $scheduledAt, $deprecatedIds)) {
                    continue;
                }

                $body = $settings->render($member, $churchName);

                $delivery = ScheduledSmsDelivery::create([
                    'branch_id' => $settings->branch_id,
                    'phone' => $member->phone,
                    'message_body' => $body,
                    'scheduled_at' => $scheduledAt,
                    'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
                    'source_type' => 'birthday',
                    'source_id' => $member->id,
                ]);

                $this->dispatchSchedule($delivery->id);
                $count++;
            }
        }

        $this->logSync('Birthday automation reconfigured — rescheduled on mNotify', [
            'branch_id' => $settings->branch_id,
            'deprecated' => count($deprecatedIds),
            'created' => $count,
        ]);

        return $count;
    }

    /**
     * Cancel every future scheduled message for a birthday automation's
     * branch (used when the automation is deactivated). mNotify jobs are
     * cancelled via CancelScheduledSmsJob; locally-only pending rows are
     * marked cancelled inline.
     */
    public function cancelBirthdayDeliveries(string $branchId, string $reason): void
    {
        $rows = ScheduledSmsDelivery::query()
            ->where('source_type', 'birthday')
            ->where('branch_id', $branchId)
            ->active()
            ->where('scheduled_at', '>=', now())
            ->get();

        foreach ($rows as $delivery) {
            $this->deprecateDelivery($delivery);
        }

        $this->logSync('Birthday automation stopped — remote schedules cancelled', array_filter([
            'branch_id' => $branchId,
            'reason' => $reason,
            'cancelled' => $rows->count(),
        ]));
    }

    /**
     * Compute the intended service date from a reminder settings row and
     * the send date. Mirrors the DOW mapping used by SendServiceReminders.
     */
    public function computeIntendedServiceDate(
        ServiceReminderSettings $settings,
        Carbon $date,
    ): Carbon {
        $serviceDow = match ($settings->serviceType?->slug) {
            'sunday_adult', 'sunday_children' => Carbon::SUNDAY,
            'midweek_service', 'bible_study' => Carbon::WEDNESDAY,
            'prayer_meeting' => Carbon::FRIDAY,
            default => Carbon::SUNDAY,
        };

        $d = $date->copy()->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            if ($d->dayOfWeek === $serviceDow) {
                return $d;
            }
            $d->addDay();
        }

        return $date->copy()->startOfDay();
    }

    // ─── Shared building blocks ─────────────────────────────────────

    /**
     * Generate (without dispatching) the upcoming reminder deliveries for
     * one settings row on one concrete date, skipping rows that already
     * cover the (source, phone, date) slot.
     *
     * $ignoredIds — delivery IDs deprecated by the current resync. Their
     * cancelled tombstones don't block regeneration (cancel-old-recreate).
     *
     * Returns the created delivery IDs.
     */
    protected function collectReminderForSettings(
        ServiceReminderSettings $settings,
        Carbon $date,
        array $ignoredIds,
    ): array {
        $ids = [];

        if ($date->dayOfWeek !== $settings->send_day_of_week) {
            return $ids;
        }

        $scheduledAt = $date->copy()->hour($settings->send_hour)->minute(0)->second(0);

        if ($scheduledAt->isPast()) {
            return $ids;
        }

        $intendedDate = $this->computeIntendedServiceDate($settings, $date);
        $serviceName = $settings->serviceType?->name ?? 'Service';
        $churchName = $settings->branch?->name ?? config('church.name', 'Your church');
        $serviceTime = $settings->serviceTimeLabel();

        $members = Member::eligibleForSms()
            ->where('branch_id', $settings->branch_id)
            ->get();

        foreach ($members as $member) {
            if ($this->isAlreadyScheduled('reminder', $settings->id, $member->phone, $scheduledAt, $ignoredIds)) {
                continue;
            }

            $body = $settings->render($member, $serviceName, $intendedDate, $serviceTime, $churchName);

            $ids[] = ScheduledSmsDelivery::create([
                'branch_id' => $settings->branch_id,
                'phone' => $member->phone,
                'message_body' => $body,
                'scheduled_at' => $scheduledAt,
                'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
                'source_type' => 'reminder',
                'source_id' => $settings->id,
            ])->id;
        }

        return $ids;
    }

    /**
     * Mark every future active delivery in $query as being replaced:
     * remote jobs get a queued cancellation, locally-orphaned pending rows
     * are cancelled inline. Returns the IDs that were deprecated so the
     * regeneration step can safely ignore their tombstones.
     *
     * @return list<string>
     */
    protected function deprecateFutureDeliveries(Builder $query): array
    {
        $rows = (clone $query)
            ->active()
            ->where('scheduled_at', '>=', now())
            ->get();

        $ids = [];

        foreach ($rows as $delivery) {
            $this->deprecateDelivery($delivery);
            $ids[] = $delivery->id;
        }

        return $ids;
    }

    /**
     * Deprecate a single delivery: remote-scheduled rows are dispatched
     * to CancelScheduledSmsJob so the mNotify job is cancelled/defused;
     * pending_api rows that never reached mNotify are cancelled locally.
     */
    protected function deprecateDelivery(ScheduledSmsDelivery $delivery): void
    {
        if ($delivery->status === ScheduledSmsDelivery::STATUS_PENDING_API && ! $delivery->mnotify_job_id) {
            $delivery->markCancelled();

            return;
        }

        dispatch_sync(new CancelScheduledSmsJob($delivery->id));
    }

    /**
     * Dispatch a single pending delivery to mNotify's scheduling API.
     *
     * Runs synchronously (dispatch_sync): configure-time resyncs execute
     * inside the admin request, so by the time the API responds the rows
     * are already scheduled_remote with a confirmed mnotify_job_id.
     */
    protected function dispatchSchedule(string $deliveryId): void
    {
        dispatch_sync(new DispatchScheduledSmsToMnotifyJob($deliveryId));
    }

    /**
     * Idempotency check: has this event already been scheduled (or
     * dispatched) for the given date — or was it explicitly cancelled?
     *
     * By default cancelled rows act as tombstones so a re-run never
     * resurrects a delivery an admin deliberately cancelled or that the
     * system defused against mNotify. When $ignoredIds is supplied, that
     * resync's own tombstones are excluded so a reconfiguration can
     * legitimately recreate its messages.
     */
    protected function isAlreadyScheduled(
        string $sourceType,
        string $sourceId,
        ?string $phone,
        Carbon $scheduledAt,
        array $ignoredIds = [],
    ): bool {
        $query = ScheduledSmsDelivery::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereDate('scheduled_at', $scheduledAt->toDateString());

        if ($phone !== null) {
            $query->where('phone', $phone);
        }

        $query->where(function (Builder $q) use ($ignoredIds) {
            $q->whereIn('status', [
                ScheduledSmsDelivery::STATUS_PENDING_API,
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
                ScheduledSmsDelivery::STATUS_DISPATCHED,
            ]);

            $q->orWhere(function (Builder $c) use ($ignoredIds) {
                $c->whereIn('status', [
                    ScheduledSmsDelivery::STATUS_CANCELLED,
                    ScheduledSmsDelivery::STATUS_CANCELLED_REMOTE,
                ]);

                if ($ignoredIds !== []) {
                    $c->whereNotIn('id', $ignoredIds);
                }
            });
        });

        return $query->exists();
    }

    /**
     * Resolve the forward window, preferring an explicit day count over
     * the configured default (services.mnotify.schedule_days).
     */
    protected function windowDays(?int $days): int
    {
        return $days ?? (int) config('services.mnotify.schedule_days', self::DEFAULT_WINDOW_DAYS);
    }

    /**
     * Structured sync logging shared across the resync paths.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logSync(string $message, array $context): void
    {
        Log::info($message, $context);
    }
}
