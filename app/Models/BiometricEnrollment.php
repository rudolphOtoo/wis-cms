<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a device-local fingerprint user id to a member.
 *
 * device_id NULL = GLOBAL enrollment, valid on any device in the branch.
 * Non-null = scoped to that single device.
 *
 * The resolver (BiometricMemberResolver) caches these lookups so the scan
 * endpoint never touches the database on the hot path.
 */
class BiometricEnrollment extends Model
{
    use BelongsToBranch, HasFactory, HasUuids;

    public const MODE_FINGERPRINT = 'fingerprint';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'device_id', 'member_id', 'device_user_id',
        'verification_mode', 'template_hash', 'is_active', 'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'enrolled_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }
}
