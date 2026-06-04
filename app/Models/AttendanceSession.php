<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AttendanceSession extends Model
{
    use BelongsToBranch, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'service_type_id', 'department_id', 'cell_id', 'service_date', 'notes', 'recorded_by', 'follow_up_status', 'follow_up_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'follow_up_sent_at' => 'datetime',
        ];
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function cell()
    {
        return $this->belongsTo(Cell::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class, 'session_id');
    }

    public function getAdultCountAttribute(): int
    {
        return $this->records()->whereNotNull('member_id')->where('is_present', true)->count();
    }

    public function getChildrenCountAttribute(): int
    {
        return $this->records()->whereNotNull('child_id')->where('is_present', true)->count();
    }

    public function getTotalCountAttribute(): int
    {
        return $this->adult_count + $this->children_count;
    }
}
