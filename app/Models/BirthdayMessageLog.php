<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthdayMessageLog extends Model
{
    use BelongsToBranch;
    use HasUuids;

    public const STATUS_SENT = 'sent';

    public const STATUS_NO_PHONE = 'no_phone';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'branch_id',
        'member_id',
        'sent_at',
        'status',
        'phone_used',
        'message_body',
        'error_message',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    // ── Query scopes ───────────────────────────────────────────────

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('sent_at', now('Africa/Accra')->toDateString());
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Did this member already receive a birthday message today?
     * Used by the sender for idempotency.
     */
    public static function memberSentToday(string $memberId): bool
    {
        return static::query()
            ->where('member_id', $memberId)
            ->whereDate('sent_at', now('Africa/Accra')->toDateString())
            ->exists();
    }
}
