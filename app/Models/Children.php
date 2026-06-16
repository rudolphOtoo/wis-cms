<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Children extends Model
{
    use BelongsToBranch, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'children';   // explicit — Laravel can't pluralise "children"

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'guardian_member_id', 'first_name', 'last_name',
        'gender', 'date_of_birth', 'class_group', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function guardian()
    {
        return $this->belongsTo(Member::class, 'guardian_member_id');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
