<?php
namespace App\Models;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    protected function getCauser(): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->causer_type && $this->causer_id
            ? (new $this->causer_type)->newQuery()->find($this->causer_id)
            : null;
    }
}