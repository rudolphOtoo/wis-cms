<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DepartmentMember extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'department_members';

    protected $fillable = [
        'department_id', 'member_id', 'role', 'joined_at',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
