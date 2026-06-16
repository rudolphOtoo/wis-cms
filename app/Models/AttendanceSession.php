<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    use BelongsToBranch, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'service_type_id', 'department_id', 'cell_id',
        'service_date', 'notes', 'recorded_by', 'follow_up_status', 'follow_up_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'follow_up_sent_at' => 'datetime',
        ];
    }

    // ─── Relationships ─────────────────────────────────────────────────────

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function cell(): BelongsTo
    {
        return $this->belongsTo(Cell::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'session_id');
    }

    // ─── Computed attributes ───────────────────────────────────────────────

    /**
     * Count of present adult (member) attendees for this session.
     *
     * PERF-01 FIX — relation-aware computation:
     * The previous implementation always called $this->records() (a new query
     * builder), which fired a fresh COUNT(*) per session even when the caller
     * had already eager-loaded the relation with ->with('records').
     *
     * Now: if 'records' is already loaded in memory, we filter the collection
     * in PHP (zero extra queries). Only when 'records' has NOT been loaded do
     * we fall back to a targeted COUNT query — this covers single-session
     * show endpoints that deliberately don't eager-load all records.
     *
     * Contract for callers: always ->with('records') when iterating sessions.
     */
    public function getAdultCountAttribute(): int
    {
        if ($this->relationLoaded('records')) {
            return $this->records
                ->filter(fn (AttendanceRecord $r) => $r->member_id !== null && $r->is_present)
                ->count();
        }

        return $this->records()
            ->whereNotNull('member_id')
            ->where('is_present', true)
            ->count();
    }

    /**
     * Count of present children attendees for this session.
     *
     * @see getAdultCountAttribute() for the relationLoaded rationale.
     */
    public function getChildrenCountAttribute(): int
    {
        if ($this->relationLoaded('records')) {
            return $this->records
                ->filter(fn (AttendanceRecord $r) => $r->child_id !== null && $r->is_present)
                ->count();
        }

        return $this->records()
            ->whereNotNull('child_id')
            ->where('is_present', true)
            ->count();
    }

    public function getTotalCountAttribute(): int
    {
        return $this->adult_count + $this->children_count;
    }
}
