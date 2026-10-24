<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A biometric scanner deployed at a church entrance.
 *
 * Devices are addressed by serial_number over the wire. The ingest secret
 * (services.biometric.webhook_secret) authenticates the whole fleet; this
 * row records which branch a device feeds and whether it is active.
 */
class BiometricDevice extends Model
{
    use BelongsToBranch, HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'name', 'serial_number', 'location',
        'secret_hash', 'is_active', 'last_seen_at',
    ];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(BiometricEnrollment::class, 'device_id');
    }
}
