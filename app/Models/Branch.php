<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name', 'location', 'address', 'phone', 'email', 'is_active', 'follow_up_enabled', 'follow_up_delay_hours', 'follow_up_present_template', 'follow_up_absent_template',
        'engagement_window_weeks', 'at_risk_threshold_pct', 'inactive_weeks',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'follow_up_enabled' => 'boolean',
            'follow_up_delay_hours' => 'integer',
            'engagement_window_weeks' => 'integer',
            'at_risk_threshold_pct' => 'integer',
            'inactive_weeks' => 'integer',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function members()
    {
        return $this->hasMany(Member::class);
    }
}
