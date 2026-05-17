<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'branch_id', 'member_number', 'first_name', 'last_name',
        'other_names', 'gender', 'date_of_birth', 'phone', 'email',
        'address', 'occupation', 'marital_status', 'join_date',
        'is_baptised', 'baptism_date', 'status', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'join_date'     => 'date',
            'baptism_date'  => 'date',
            'is_baptised'   => 'boolean',
        ];
    }

    // Auto-generate member number
    protected static function booted(): void
    {
        static::creating(function (Member $member) {
            if (empty($member->member_number)) {
                $year  = now()->format('Y');
                $count = static::whereYear('created_at', $year)->count() + 1;
                $member->member_number = 'WIS-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
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

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'department_members')
                    ->withPivot('role', 'joined_at')
                    ->withTimestamps();
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
