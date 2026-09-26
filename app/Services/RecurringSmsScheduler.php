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

                // Reclaim an orphan from an aborted batch instead of inserting
                // a second row for the same member/slot.
                $orphan = $this->reclaimOrphanedSlot('birthday', $member->id, null, $scheduledAt);

                if ($orphan) {
                    $orphan->update(['message_body' => $body]);
                    $ids[] = $orphan->id;

                    continue;
                }

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

                $orphan = $this->reclaimOrphanedSlot('birthday', $member->id, null, $scheduledAt);

                if ($orphan) {
                    $orphan->update(['message_body' => $body]);
                    $this->dispatchSchedule($orphan->id);
                    $count++;

                    continue;
                }

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
        $cancelled = $this->cancelFutureDeliveries(
            ScheduledSmsDelivery::query()
                ->where('source_type', 'birthday')
                ->where('branch_id', $branchId),
            $reason,
        );

        $this->logSync('Birthday automation stopped — remote schedules cancelled', [
            'branch_id' => $branchId,
            'reason' => $reason,
            'cancelled' => $cancelled,
        ]);
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

    // ─── Public cancellation API ─────────────────────────────────

    /**
     * Cancel one delivery on mNotify's cloud, synchronously and safely.
     *
     * This is the single entry point every admin-initiated cancellation
     * must go through (UI toggle, per-dispatch cancel endpoint, observer
     * deactivation). It guarantees:
     *
     *   1. a real DELETE /scheduled/{id} is issued to mNotify, with the
     *      far-future defusal as fallback when the provider's DELETE is
     *      unavailable, so the message can never fire from the cloud;
     *   2. the local row is transitioned to cancelled_remote (or
     *      cancelled when it never reached the provider);
     *   3. a provider/network failure is logged and queued in
     *      pending_remote_schedules instead of aborting the caller.
     */
    public function cancelDeliveryOnMnotify(ScheduledSmsDelivery $delivery): void
    {
        $this->dispatchCancel($delivery->id);
    }

    /**
     * Push one pending delivery to mNotify's cloud, synchronously and
     * safely. Never throws — a failure is logged and retried by
     * sync:pending-schedules.
     */
    public function pushDeliveryToMnotify(ScheduledSmsDelivery $delivery): void
    {
        $this->dispatchSchedule($delivery->id);
    }

    /**
     * Cancel every future active delivery matching $query on mNotify.
     *
     * Returns the number of deliveries processed. One unreachable
     * recipient never prevents the rest of the batch from being defused.
     */
    public function cancelFutureDeliveries(Builder $query, string $reason): int
    {
        $rows = (clone $query)
            ->active()
            ->where('scheduled_at', '>=', now())
            ->get();

        foreach ($rows as $delivery) {
            $this->deprecateDelivery($delivery);
        }

        $this->logSync('Remote SMS schedules cancelled', array_filter([
            'reason' => $reason,
            'cancelled' => $rows->count(),
        ]));

        return $rows->count();
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

            $orphan = $this->reclaimOrphanedSlot('reminder', $settings->id, $member->phone, $scheduledAt);

            if ($orphan) {
                $orphan->update([
                    'phone' => $member->phone,
                    'message_body' => $body,
                ]);
                $ids[] = $orphan->id;

                continue;
            }

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

        $this->dispatchCancel($delivery->id);
    }

    /**
     * Dispatch a single pending delivery to mNotify's scheduling API.
     *
     * Runs synchronously (dispatch_sync): configure-time resyncs execute
     * inside the admin request, so by the time the API responds the rows
     * are already scheduled_remote with a confirmed mnotify_job_id.
     *
     * Never throws. Both DispatchScheduledSmsToMnotifyJob and
     * CancelScheduledSmsJob persist a PendingRemoteSchedule row before
     * rethrowing, so swallowing the exception here loses nothing: the
     * offline queue (sync:pending-schedules) still retries the failed
     * member while the rest of the batch proceeds. Letting the exception
     * escape would abort every remaining recipient in the batch and leave
     * them stuck in pending_api with no job ID — the exact failure mode
     * that stranded 111 members.
     */
    protected function dispatchSchedule(string $deliveryId): void
    {
        $this->runResiliently(
            fn () => dispatch_sync(new DispatchScheduledSmsToMnotifyJob($deliveryId)),
            'schedule',
            $deliveryId,
        );
    }

    /**
     * Dispatch a single cancellation to mNotify synchronously, isolating
     * failures to the one delivery being cancelled.
     */
    protected function dispatchCancel(string $deliveryId): void
    {
        $this->runResiliently(
            fn () => dispatch_sync(new CancelScheduledSmsJob($deliveryId)),
            'cancel',
            $deliveryId,
        );
    }

    /**
     * Run one mNotify operation, converting any failure into a log entry so
     * a single bad recipient cannot abort the surrounding batch.
     */
    protected function runResiliently(callable $operation, string $action, string $deliveryId): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            Log::warning("RecurringSmsScheduler: {$action} failed for delivery {$deliveryId} — continuing batch", [
                'delivery_id' => $deliveryId,
                'action' => $action,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Idempotency check: has this event already been scheduled (or
     * dispatched) for the given date — or was it explicitly cancelled?
     *
     * A row only counts as "already handled" when mNotify actually
     * confirmed it:
     *
     *   - scheduled_remote / dispatched — provider-confirmed, skip.
     *   - pending_api WITH a mnotify_job_id — the provider accepted the
     *     push and the local status write is the only thing missing, so
     *     re-pushing would double-send. Skip.
     *   - pending_api WITHOUT a mnotify_job_id — an orphan left behind
     *     when a batch aborted mid-dispatch. The member has NOT been
     *     scheduled remotely, so this must NOT count as done; otherwise
     *     the row is permanently stuck and the member silently never
     *     receives the message. Deliberately excluded so the collector
     *     reclaims it via reclaimOrphanedSlot().
     *   - cancelled / cancelled_remote — tombstones, honored unless the
     *     current resync deprecated them itself ($ignoredIds).
     */
    protected function isAlreadyScheduled(
        string $sourceType,
        string $sourceId,
        ?string $phone,
        Carbon $scheduledAt,
        array $ignoredIds = [],
    ): bool {
        $query = $this->slotQuery($sourceType, $sourceId, $phone, $scheduledAt);

        $query->where(function (Builder $q) use ($ignoredIds) {
            // Provider-confirmed rows.
            $q->whereIn('status', [
                ScheduledSmsDelivery::STATUS_SCHEDULED_REMOTE,
                ScheduledSmsDelivery::STATUS_DISPATCHED,
            ]);

            // Accepted by the provider, local status write pending.
            $q->orWhere(function (Builder $c) {
                $c->where('status', ScheduledSmsDelivery::STATUS_PENDING_API)
                    ->whereNotNull('mnotify_job_id')
                    ->where('mnotify_job_id', '<>', '');
            });

            // Admin tombstones / defused jobs.
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
     * Find a pending_api row for this exact slot that never received an
     * mNotify job ID — i.e. an orphan from an aborted batch.
     *
     * The collectors reuse these rows instead of inserting new ones, so a
     * retry repairs the original record rather than growing the table with
     * a second row for the same member/slot.
     */
    protected function reclaimOrphanedSlot(
        string $sourceType,
        string $sourceId,
        ?string $phone,
        Carbon $scheduledAt,
    ): ?ScheduledSmsDelivery {
        $query = $this->slotQuery($sourceType, $sourceId, $phone, $scheduledAt)
            ->where('status', ScheduledSmsDelivery::STATUS_PENDING_API)
            ->where(fn ($q) => $q->whereNull('mnotify_job_id')->orWhere('mnotify_job_id', ''))
            ->oldest('created_at');

        $orphan = $query->first();

        if (! $orphan) {
            return null;
        }

        $orphan->update([
            'status' => ScheduledSmsDelivery::STATUS_PENDING_API,
            'error_message' => null,
        ]);

        return $orphan;
    }

    /**
     * Base query for the (source, phone, date) scheduling slot.
     */
    protected function slotQuery(
        string $sourceType,
        string $sourceId,
        ?string $phone,
        Carbon $scheduledAt,
    ): Builder {
        $query = ScheduledSmsDelivery::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereDate('scheduled_at', $scheduledAt->toDateString());

        if ($phone !== null) {
            $query->where('phone', $phone);
        }

        return $query;
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
