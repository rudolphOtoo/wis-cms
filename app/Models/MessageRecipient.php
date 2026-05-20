<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MessageRecipient extends Model
{
    use HasUuids;

    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'message_id', 'member_id', 'phone', 'email',
        'delivery_status', 'delivered_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
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
