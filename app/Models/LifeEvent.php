<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LifeEvent extends Model
{
    use BelongsToBranch, HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'recorded_by_user_id', 'type', 'event_date', 'burial_date',
        'member_id', 'father_member_id', 'first_name', 'last_name',
        'father_first_name', 'father_last_name',
        'mother_first_name', 'mother_last_name', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'burial_date' => 'date',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function fatherMember()
    {
        return $this->belongsTo(Member::class, 'father_member_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
