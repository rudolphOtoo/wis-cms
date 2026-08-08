<?php

namespace App\Diocese\Modules\Confirmations\Models;

use App\Models\Member;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Confirmation extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'member_id', 'recorded_by_user_id', 'confirmed_at',
        'officiating_clergy', 'location', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'date',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
