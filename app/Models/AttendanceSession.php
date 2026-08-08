<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceSession extends Model
{
    use BelongsToBranch, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'service_type_id', 'department_id', 'cell_id',
        'service_date', 'notes', 'attendance_mode', 'male_count', 'female_count', 'children_count',
        'recorded_by', 'follow_up_status', 'follow_up_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'attendance_mode' => 'string',
            'male_count' => 'integer',
            'female_count' => 'integer',
            'children_count' => 'integer',
            'follow_up_sent_at' => 'datetime',
        ];
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────

    /**
     * Scope the query to only include sessions the given user is allowed to see.
     *
     * Admin roles (super_admin, pastor, secretary, finance_officer) see all
     * sessions. Cell leaders see only their own cells' sessions. Department
     * leaders see only their own departments' sessions.
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        if ($user->hasAnyRole(['super_admin', 'pastor', 'secretary', 'finance_officer'])) {
            return;
        }

        $query->where(function (Builder $q) use ($user) {
            $hasConditions = false;

            if ($user->hasRole('cell_leader')) {
                $cellIds = Cell::where('leader_user_id', $user->id)->pluck('id');
                if ($cellIds->isNotEmpty()) {
                    $q->whereIn('cell_id', $cellIds);
                    $hasConditions = true;
                }
            }

            if ($user->hasRole('department_leader')) {
                $deptIds = Department::where('leader_user_id', $user->id)->pluck('id');
                if ($deptIds->isNotEmpty()) {
                    $q->orWhereIn('department_id', $deptIds);
                    $hasConditions = true;
                }
            }

            if (! $hasConditions) {
                $q->whereRaw('0 = 1');
            }
        });
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

    public function counts(): HasOne
    {
        return $this->hasOne(AttendanceSessionCount::class, 'session_id');
    }

    // ─── Computed attributes ───────────────────────────────────────────────

    /**
     * Count of present adult (member) attendees for this session.
     *
     * Mode-aware: register sessions derive the count from per-person
     * attendance_records; headcount sessions use the stored male+female
     * tally.
     *
     * PERF-01 FIX — relation-aware computation:
     * The previous implementation always called $this->records() (a new query
     * builder), which fired a fresh COUNT(*) per session even when the caller
     * had already eager-loaded the relation with ->with('records').
     *
     * Now: if 'counts' (the pre-aggregated view) or 'records' is already
     * loaded in memory, we read from the in-memory data (zero extra
     * queries). Only when neither has been loaded do we fall back to a
     * targeted COUNT query — this covers single-session show endpoints
     * that deliberately don't eager-load.
     *
     * Contract for callers: always ->with('counts') (or ->with('records'))
     * when iterating sessions.
     */
    public function getAdultCountAttribute(): int
    {
        if ($this->relationLoaded('counts')) {
            return (int) ($this->counts?->adult_count ?? 0);
        }

        if ($this->attendance_mode === 'headcount') {
            return (int) $this->male_count + (int) $this->female_count;
        }

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
     * @see getAdultCountAttribute() for the mode-aware, relation-loaded rationale.
     */
    public function getChildrenCountAttribute(): int
    {
        if ($this->relationLoaded('counts')) {
            return (int) ($this->counts?->children_count ?? 0);
        }

        if ($this->attendance_mode === 'headcount') {
            // NOTE: read the raw attribute, not $this->children_count —
            // this accessor shadows the stored column and would recurse.
            return (int) ($this->getAttributes()['children_count'] ?? 0);
        }

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
        if ($this->relationLoaded('counts')) {
            return (int) ($this->counts?->total_count ?? 0);
        }

        return $this->adult_count + $this->children_count;
    }
}
