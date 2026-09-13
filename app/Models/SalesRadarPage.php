<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesRadarPage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'fetched_at' => 'datetime', 'retry_at' => 'datetime', 'attempts' => 'integer'];
    }
}
