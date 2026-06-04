<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cell extends Model
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

    // A cell HAS MANY members (one-to-many) — the inverse of Member::cell().
    // This differs from Department::members() which is many-to-many.
    public function members()
    {
        return $this->hasMany(Member::class);
    }

    public function getMembersCountAttribute(): int
    {
        // If withCount() or loadCount() has been used by the caller,
        // Eloquent stores the value in $this->attributes['members_count'].
        // Respect that — it lets callers filter the count (e.g. only
        // active members) without this accessor overriding it.
        //
        // Without this check, $cell->withCount(['members' => fn ($q) =>
        // $q->where('status', 'active')])->first()->members_count returns
        // the UNFILTERED count, because the accessor re-queries every time.
        if (array_key_exists('members_count', $this->attributes)) {
            return (int) $this->attributes['members_count'];
        }

        return $this->members()->count();
    }
}
