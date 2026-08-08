<?php

namespace App\Models;

use App\Diocese\Contracts\MemberNumberGenerator;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Member extends Model
{
    use BelongsToBranch, HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'cell_id', 'member_number', 'first_name', 'last_name',
        'other_names', 'gender', 'date_of_birth', 'phone', 'email',
        'address', 'occupation', 'marital_status', 'join_date',
        'is_baptised', 'baptism_date', 'status', 'last_attendance_date',
        'welfare_flag', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'join_date' => 'date',
            'baptism_date' => 'date',
            'is_baptised' => 'boolean',
            'last_attendance_date' => 'date',
        ];
    }

    /**
     * Auto-generate member number atomically.
     *
     * The numbering scheme is a per-profile STRATEGY (see
     * App\Diocese\Contracts\MemberNumberGenerator). The default WIS
     * implementation keeps the original WIS-{year}-{0001} format and uses a
     * pessimistic row-level lock to prevent race conditions when multiple
     * members are created simultaneously.
     */
    protected static function booted(): void
    {
        static::creating(function (Member $member) {
            if (empty($member->member_number)) {
                $member->member_number = DB::transaction(fn () => app(MemberNumberGenerator::class)->generate($member));
            }
        });
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

    public function pastoralNotes()
    {
        return $this->hasMany(PastoralNote::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(preg_replace('/\\s+/', ' ', "{$this->first_name} {$this->other_names} {$this->last_name}"));
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
