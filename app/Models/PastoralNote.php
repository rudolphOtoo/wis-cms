<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PastoralNote extends Model
{
    use BelongsToBranch, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'member_id', 'author_user_id', 'branch_id', 'category',
        'title', 'body', 'follow_up_required', 'follow_up_date',
        'follow_up_completed',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_required' => 'boolean',
            'follow_up_completed' => 'boolean',
            'follow_up_date' => 'date',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
