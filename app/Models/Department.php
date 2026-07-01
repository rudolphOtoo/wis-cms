<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToBranch, HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'name', 'description', 'leader_user_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    public function members()
    {
        return $this->belongsToMany(Member::class, 'department_members')
            ->using(DepartmentMember::class)
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function getMembersCountAttribute(): int
    {
        if (array_key_exists('members_count', $this->attributes)) {
            return (int) $this->attributes['members_count'];
        }

        return $this->members()->count();
    }
}
