<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Branch scoping is NOT applied here — the parent Message
 * owns the branch boundary. Recipients are always accessed
 * through the message relation.
 */
class MessageRecipient extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'message_id', 'member_id', 'phone', 'email',
        'delivery_status', 'delivered_at', 'failure_reason', 'rendered_body',
        'email_sent_at', 'sms_sent_at', 'delivery_attempts',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'sms_sent_at' => 'datetime',
            'delivery_attempts' => 'integer',
        ];
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
