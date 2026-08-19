<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinanceCategory extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name', 'type', 'description', 'is_active', 'display_order', 'payment_type',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'category_id');
    }
}
