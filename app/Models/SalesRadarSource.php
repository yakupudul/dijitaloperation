<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesRadarSource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'checked_at' => 'datetime', 'next_at' => 'datetime', 'interval_minutes' => 'integer', 'failures' => 'integer'];
    }
}
