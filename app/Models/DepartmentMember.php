<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DepartmentMember extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'department_members';

    protected $fillable = [
        'department_id', 'member_id', 'role', 'joined_at',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
