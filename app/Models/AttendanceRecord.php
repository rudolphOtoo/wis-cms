<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PERF-07: SoftDeletes added so mis-marked attendance sessions can be
 * corrected without permanent data loss. The deleted_at column also
 * provides an implicit audit trail (when records were removed and by
 * whom, via the activity log on the parent AttendanceSession).
 *
 * Raw DB::table() queries against attendance_records must now explicitly
 * add ->whereNull('ar.deleted_at') — Eloquent model queries handle this
 * automatically via the SoftDeletes global scope.
 */
class AttendanceRecord extends Model
{
    use BelongsToBranch, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'session_id', 'member_id', 'child_id', 'is_present',
    ];

    protected function casts(): array
    {
        return [
            'is_present' => 'boolean',
        ];
    }

    // ─── Relationships ─────────────────────────────────────────────────────

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'session_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Children::class, 'child_id');
    }
}
