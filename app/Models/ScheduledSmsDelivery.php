<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks each SMS scheduled via mNotify's remote scheduling API.
 *
 * Lifecycle: pending_api → scheduled_remote → dispatched/cancelled/failed/failed_provider
 */
class ScheduledSmsDelivery extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUS_PENDING_API = 'pending_api';

    public const STATUS_SCHEDULED_REMOTE = 'scheduled_remote';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CANCELLED_REMOTE = 'cancelled_remote';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FAILED_PROVIDER = 'failed_provider';

    /**
     * Source type written on every service-reminder delivery. Shared by
     * the collector, the hourly fallback sender and the dedupe command so
     * all three agree on which rows are reminders.
     */
    public const SOURCE_REMINDER = 'reminder';

    /**
     * Statuses that mean this recipient is going to receive the message,
     * or already has. Any of them must stop a second attempt.
     */
    public const IN_FLIGHT_STATUSES = [
        self::STATUS_PENDING_API,
        self::STATUS_SCHEDULED_REMOTE,
        self::STATUS_DISPATCHED,
    ];

    /** Statuses that mean the message was deliberately withdrawn. */
    public const WITHDRAWN_STATUSES = [
        self::STATUS_CANCELLED,
        self::STATUS_CANCELLED_REMOTE,
    ];

    protected $fillable = [
        'branch_id',
        'mnotify_job_id',
        'phone',
        'message_body',
        'scheduled_at',
        'status',
        'source_type',
        'source_id',
        'created_by',
        'error_message',
        'failure_reason',
        'mnotify_response',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'mnotify_response' => 'array',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pendingSchedules()
    {
        return $this->hasMany(PendingRemoteSchedule::class, 'scheduled_sms_delivery_id');
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopePendingApi($query)
    {
        return $query->where('status', self::STATUS_PENDING_API);
    }

    public function scopeScheduledRemote($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED_REMOTE);
    }

    public function scopeCancelledRemote($query)
    {
        return $query->where('status', self::STATUS_CANCELLED_REMOTE);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING_API, self::STATUS_SCHEDULED_REMOTE]);
    }

    public function scopeForSource($query, string $type, ?string $id = null)
    {
        $query->where('source_type', $type);

        if ($id !== null) {
            $query->where('source_id', $id);
        }

        return $query;
    }

    // ─── State helpers ────────────────────────────────────────

    public function markScheduledRemote(string $mnotifyJobId, ?array $response = null): void
    {
        $this->update([
            'status' => self::STATUS_SCHEDULED_REMOTE,
            'mnotify_job_id' => $mnotifyJobId,
            'mnotify_response' => $response,
            'error_message' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
        ]);
    }

    public function markFailedProvider(string $reason, ?array $response = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED_PROVIDER,
            'failure_reason' => $reason,
            'mnotify_response' => $response,
        ]);
    }

    public function markCancelled(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    public function markCancelledRemote(?array $response = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED_REMOTE,
            'mnotify_response' => $response,
        ]);
    }

    public function markDispatched(?array $response = null): void
    {
        $update = ['status' => self::STATUS_DISPATCHED];
        if ($response !== null) {
            $update['mnotify_response'] = $response;
        }
        $this->update($update);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING_API, self::STATUS_SCHEDULED_REMOTE]);
    }

    /**
     * Has mNotify actually accepted this message?
     *
     * True when the provider holds a job for it — either because the status
     * says so, or because a push succeeded and only the local status write
     * is missing. Distinguishes a real remote message from a pending_api
     * orphan left behind by a batch that aborted mid-push.
     */
    public function isProviderBacked(): bool
    {
        return in_array($this->status, [
            self::STATUS_SCHEDULED_REMOTE,
            self::STATUS_DISPATCHED,
        ], true) || (
            $this->status === self::STATUS_PENDING_API
            && $this->mnotify_job_id !== null
            && $this->mnotify_job_id !== ''
        );
    }

    // ─── Reminder idempotency ───────────────────────────────────

    /**
     * Has this phone number already been given — or been queued to be
     * given — a service reminder for this calendar day?
     *
     * This is the hard one-message-per-member-per-day guarantee, used by
     * the hourly fallback sender before it creates anything.
     *
     * Deliberately NOT scoped to `source_id`. `source_id` is the settings
     * row that created the delivery, so two active settings rows pointing
     * at the same branch/day/hour produce two rows with two different
     * `source_id`s for the very same recipient. Matching on `source_id`
     * therefore let each row "prove" the other one had not already
     * scheduled the message — which is how members ended up with two or
     * three different phrasings of the same reminder at noon. Keyed on
     * phone + day, neither row can excuse itself.
     *
     * Deliberately NOT scoped to `branch_id` either: two members sharing
     * a phone (a husband and wife on one handset) are still one human
     * receiving one text.
     *
     * Withdrawn rows do NOT block. A cancelled or cancelled_remote
     * delivery was never sent, so the member still needs to hear from us
     * and standing down would silently drop them.
     */
    public static function alreadyQueuedForReminder(string $phone, Carbon $sendDate): bool
    {
        if ($phone === '') {
            return false;
        }

        return isset(static::reminderPhonesInFlight($sendDate, [$phone])[$phone]);
    }

    /**
     * The batch form of alreadyQueuedForReminder().
     *
     * Callers fan this out over every member of a branch, so probing once
     * per member turned a 300-member dispatch into 300 round trips. One
     * indexed lookup answers for the whole batch instead.
     *
     * @param  list<string>  $phones  candidates, e.g. a branch's members
     * @return array<string, true> keys are the phones already holding a slot
     */
    public static function reminderPhonesInFlight(Carbon $sendDate, array $phones): array
    {
        return static::claimedPhones(
            $sendDate,
            $phones,
            [],
            fn (Builder $q) => $q->whereIn('status', self::IN_FLIGHT_STATUSES),
        );
    }

    /**
     * Has some *other* automation already claimed this recipient's slot
     * on the provider? Used by the rolling collector before it inserts a
     * new delivery, so a second settings row in the same dispatch slot
     * cannot add a second message.
     *
     * Same phone + day key as alreadyQueuedForReminder(), so the two
     * checks can never disagree about who owns a slot.
     *
     * Only provider-backed rows count — a job mNotify has accepted, under
     * whatever settings row created it. A `pending_api` row with no job ID
     * is an orphan from a batch that aborted mid-push; it has NOT reached
     * the member and must stay reclaimable (see
     * RecurringSmsScheduler::reclaimOrphanedSlot) or the member would
     * never be reminded at all.
     *
     * Withdrawn rows are deliberately NOT counted. A tombstone only means
     * "this automation was cancelled" — it says nothing about any other
     * row, so honouring one here would let deactivating one duplicate
     * settings row permanently silence the survivor. Per-source tombstones
     * are already respected by the collector's own source-scoped check,
     * which runs first.
     *
     * @param  list<string>  $ignoredIds  rows the current resync just deprecated
     */
    public static function reminderSlotClaimed(string $phone, Carbon $sendDate, array $ignoredIds = []): bool
    {
        if ($phone === '') {
            return false;
        }

        return isset(static::reminderPhonesClaimed($sendDate, [$phone], $ignoredIds)[$phone]);
    }

    /**
     * The batch form of reminderSlotClaimed().
     *
     * @param  list<string>  $phones  candidates, e.g. a branch's members
     * @param  list<string>  $ignoredIds
     * @return array<string, true> keys are the phones already claimed
     */
    public static function reminderPhonesClaimed(Carbon $sendDate, array $phones, array $ignoredIds = []): array
    {
        return static::claimedPhones(
            $sendDate,
            $phones,
            $ignoredIds,
            fn (Builder $q) => $q
                // Provider-confirmed, or accepted and awaiting only a
                // local status write.
                ->whereIn('status', [
                    self::STATUS_SCHEDULED_REMOTE,
                    self::STATUS_DISPATCHED,
                ])
                ->orWhere(fn (Builder $c) => $c
                    ->where('status', self::STATUS_PENDING_API)
                    ->whereNotNull('mnotify_job_id')
                    ->where('mnotify_job_id', '<>', ''),
                ),
        );
    }

    /**
     * Resolve a whole batch of candidate phones against one slot rule.
     *
     * Deliberately one place, so the single-recipient entry points and the
     * bulk paths above cannot drift apart and start disagreeing about who
     * owns a slot.
     *
     * @param  list<string>  $phones
     * @param  list<string>  $ignoredIds
     * @param  \Closure(Builder): void  $statusRule
     * @return array<string, true>
     */
    protected static function claimedPhones(
        Carbon $sendDate,
        array $phones,
        array $ignoredIds,
        \Closure $statusRule,
    ): array {
        $phones = array_values(array_unique(array_filter(
            $phones,
            fn ($phone) => $phone !== null && $phone !== '',
        )));

        if ($phones === []) {
            return [];
        }

        $found = static::reminderSlot($sendDate)
            ->whereIn('phone', $phones)
            ->when($ignoredIds !== [], fn (Builder $q) => $q->whereNotIn('id', $ignoredIds))
            ->where($statusRule)
            ->pluck('phone');

        return array_fill_keys($found->all(), true);
    }

    /**
     * The recipient + calendar day every reminder guard keys on.
     *
     * Note the date is the day the reminder is SENT, not the day of the
     * service it announces: a Saturday 8 PM reminder for a Sunday service
     * is scheduled on the Saturday, so comparing against the service date
     * would never match anything.
     *
     * Expressed as a half-open range rather than whereDate(). Laravel's
     * whereDate() compiles to `scheduled_at::date = ?`, which wraps the
     * column in a cast — Postgres then has to fetch every row this phone
     * has ever had and test the date one row at a time, because the index
     * can no longer be asked for a range. EXPLAIN showed the predicate
     * demoted from Index Cond to Filter. `>= start AND < next start` lets
     * the same index bound the scan to the day.
     */
    protected static function reminderSlot(Carbon $sendDate): Builder
    {
        $start = $sendDate->copy()->startOfDay();

        return static::query()
            ->where('source_type', self::SOURCE_REMINDER)
            ->where('scheduled_at', '>=', $start)
            ->where('scheduled_at', '<', $start->copy()->addDay());
    }
}
