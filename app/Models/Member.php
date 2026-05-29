<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Member extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'cell_id', 'member_number', 'first_name', 'last_name',
        'other_names', 'gender', 'date_of_birth', 'phone', 'email',
        'address', 'occupation', 'marital_status', 'join_date',
        'is_baptised', 'baptism_date', 'status', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'join_date' => 'date',
            'baptism_date' => 'date',
            'is_baptised' => 'boolean',
        ];
    }

    /**
     * Auto-generate member number atomically.
     * Uses pessimistic row-level lock to prevent race conditions
     * when multiple members are created simultaneously.
     */
    protected static function booted(): void
    {
        static::creating(function (Member $member) {
            if (empty($member->member_number)) {
                $year = now()->format('Y');

                DB::transaction(function () use ($member, $year) {
                    // Acquire row lock on the highest-numbered member this year
                    // withTrashed: soft-deleted members KEEP their member_number,
                    // so we must consider them when generating the next number to
                    // avoid collisions with the (still present, soft-deleted) row.
                    $last = static::withTrashed()
                        ->where('member_number', 'like', "WIS-{$year}-%")
                        ->orderByDesc('member_number')
                        ->lockForUpdate()
                        ->first();

                    $nextNumber = $last
                        ? ((int) substr($last->member_number, -4)) + 1
                        : 1;

                    $member->member_number = 'WIS-'.$year.'-'.str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
                });
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function children()
    {
        return $this->hasMany(Children::class, 'guardian_member_id');
    }

    public function cell()
    {
        // A member belongs to exactly ONE cell (or none) via cell_id.
        return $this->belongsTo(Cell::class);
    }

    /**
     * The User account linked to this Member, if any.
     * At most one User per Member (enforced by UNIQUE constraint on
     * users.member_id). NULL for members who don't have a login.
     */
    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'department_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'member_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->other_names} {$this->last_name}");
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
