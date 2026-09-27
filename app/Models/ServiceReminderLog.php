<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of every SMS reminder outcome: one row per (member,
 * service_type, intended_service_date) for sends, plus the withdrawals
 * that stopped one.
 *
 * The send rows drive idempotency (never send the same reminder twice);
 * the cancellation rows are the audit trail. They are deliberately not
 * counted as sends — a message that was withdrawn was never delivered,
 * and reporting it as anything else would misrepresent what members got.
 */
class ServiceReminderLog extends Model
{
    use BelongsToBranch;
    use HasUuids;

    protected $table = 'service_reminder_logs';

    protected $fillable = [
        'branch_id',
        'service_type_id',
        'member_id',
        'sent_at',
        'intended_service_date',
        'status',
        'phone_used',
        'message_body',
        'error_message',
        'cancelled_by',
        'detail',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'intended_service_date' => 'date',
    ];

    public const STATUS_SENT = 'sent';

    public const STATUS_NO_PHONE = 'no_phone';

    public const STATUS_FAILED = 'failed';

    /**
     * One message was withdrawn on an admin's instruction.
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * A whole automation was switched off, taking every future message
     * for that service type with it. One row, not one per member: the
     * cascade routinely retires 100+ dispatches and a log line each
     * would bury the entries an admin actually reads.
     */
    public const STATUS_CANCELLED_BATCH = 'cancelled_batch';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForServiceDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('intended_service_date', $date->toDateString());
    }

    /**
     * Idempotency helper: has this member already been sent a reminder
     * for this service type on this particular service date?
     */
    public static function alreadySent(
        string $memberId,
        string $serviceTypeId,
        Carbon $serviceDate,
    ): bool {
        return static::query()
            ->where('member_id', $memberId)
            ->where('service_type_id', $serviceTypeId)
            ->whereDate('intended_service_date', $serviceDate->toDateString())
            ->where('status', self::STATUS_SENT)
            ->exists();
    }
}
