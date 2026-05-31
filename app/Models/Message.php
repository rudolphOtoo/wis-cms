<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasUuids;

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

    public function recipients()
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public function getTotalRecipientsAttribute(): int
    {
        return $this->recipients()->count();
    }

    public function getDeliveredCountAttribute(): int
    {
        return $this->recipients()->where('delivery_status', 'delivered')->count();
    }

    public function getFailedCountAttribute(): int
    {
        return $this->recipients()->where('delivery_status', 'failed')->count();
    }
}
