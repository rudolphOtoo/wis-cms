<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceType extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name', 'slug', 'type', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }
}
