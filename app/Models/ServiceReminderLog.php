<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of every SMS reminder attempt. One row per (member,
 * service_type, intended_service_date) — used for idempotency
 * (never send the same reminder twice) and for the admin audit
 * view.
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
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'intended_service_date' => 'date',
    ];

    public const STATUS_SENT = 'sent';

    public const STATUS_NO_PHONE = 'no_phone';

    public const STATUS_FAILED = 'failed';

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
