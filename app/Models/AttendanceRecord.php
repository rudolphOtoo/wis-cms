<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use HasUuids;

    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'session_id', 'member_id', 'child_id', 'is_present',
    ];

    protected function casts(): array
    {
        return ['is_present' => 'boolean'];
    }

    public function session()
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function child()
    {
        return $this->belongsTo(Children::class);
    }
}
