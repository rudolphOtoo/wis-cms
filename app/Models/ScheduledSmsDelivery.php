<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks each SMS scheduled via mNotify's remote scheduling API.
 *
 * Lifecycle: pending_api → scheduled_remote → dispatched/cancelled/failed
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

    public const STATUS_FAILED = 'failed';

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

    public function markCancelled(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    public function markDispatched(): void
    {
        $this->update(['status' => self::STATUS_DISPATCHED]);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING_API, self::STATUS_SCHEDULED_REMOTE]);
    }
}
