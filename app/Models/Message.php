<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use BelongsToBranch, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id', 'sender_id', 'subject', 'body', 'channel',
        'status', 'recipient_group', 'department_id', 'cell_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function cell()
    {
        return $this->belongsTo(Cell::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function recipients()
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public function getTotalRecipientsAttribute(): int
    {
        if ($this->relationLoaded('recipients')) {
            return $this->recipients->count();
        }
        if (array_key_exists('recipients_count', $this->attributes)) {
            return (int) $this->attributes['recipients_count'];
        }

        return $this->recipients()->count();
    }

    public function getDeliveredCountAttribute(): int
    {
        if ($this->relationLoaded('recipients')) {
            return $this->recipients->where('delivery_status', 'delivered')->count();
        }
        if (array_key_exists('delivered_count', $this->attributes)) {
            return (int) $this->attributes['delivered_count'];
        }

        return $this->recipients()->where('delivery_status', 'delivered')->count();
    }

    public function getFailedCountAttribute(): int
    {
        if ($this->relationLoaded('recipients')) {
            return $this->recipients->where('delivery_status', 'failed')->count();
        }
        if (array_key_exists('failed_count', $this->attributes)) {
            return (int) $this->attributes['failed_count'];
        }

        return $this->recipients()->where('delivery_status', 'failed')->count();
    }
}
