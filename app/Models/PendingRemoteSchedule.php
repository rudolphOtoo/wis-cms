<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Offline-resilience queue for mNotify API requests.
 *
 * When the local desktop can't reach mNotify (no internet), the
 * request payload is stored here. The SyncPendingRemoteSchedules
 * command retries these automatically when connectivity returns.
 */
class PendingRemoteSchedule extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public const ACTION_SCHEDULE = 'schedule';

    public const ACTION_CANCEL = 'cancel';

    public const ACTION_UPDATE = 'update';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'action',
        'scheduled_sms_delivery_id',
        'payload',
        'attempts',
        'max_attempts',
        'last_attempt_at',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function scheduledSmsDelivery()
    {
        return $this->belongsTo(ScheduledSmsDelivery::class, 'scheduled_sms_delivery_id');
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('attempts', '<', DB::raw('max_attempts'))
            ->orderBy('created_at');
    }

    public function scopeForSync($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->orderBy('created_at');
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function recordAttempt(?string $error = null): void
    {
        $this->increment('attempts');
        $this->update([
            'last_attempt_at' => now(),
            'error_message' => $error,
            'status' => $error === null ? self::STATUS_COMPLETED : self::STATUS_PENDING,
        ]);
    }

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
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

    public function hasExhaustedRetries(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }
}
