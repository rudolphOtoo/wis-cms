<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only model over the attendance_session_counts PostgreSQL view.
 *
 * One row per attendance session with the mode-agnostic counts
 * (adult / children / total / male / female / records_total). The view is
 * always fresh, so this model is deliberately immutable — no writes, no
 * branch scope (the owning AttendanceSession enforces the branch boundary).
 */
class AttendanceSessionCount extends Model
{
    protected $table = 'attendance_session_counts';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'session_id';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'adult_count' => 'integer',
            'children_count' => 'integer',
            'total_count' => 'integer',
            'male_count' => 'integer',
            'female_count' => 'integer',
            'records_total' => 'integer',
        ];
    }
}
