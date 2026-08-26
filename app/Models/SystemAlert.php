<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistent admin-facing system alert.
 *
 * Unlike flash toasts, these survive page refreshes and require
 * explicit admin acknowledgement.
 */
class SystemAlert extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public const TYPE_CREDIT_DEPLETION = 'credit_depletion';

    public const TYPE_RECONCILIATION = 'reconciliation';

    public const TYPE_GENERAL = 'general';

    protected $fillable = [
        'type',
        'title',
        'message',
        'meta',
        'acknowledged_at',
        'acknowledged_by',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeUnread($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    public function ofType(string $type)
    {
        return $this->where('type', $type);
    }

    // ─── Helpers ───────────────────────────────────────────────

    public function acknowledge(?string $userId = null): void
    {
        $this->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
        ]);
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
