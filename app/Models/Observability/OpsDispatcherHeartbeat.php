<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Model;

class OpsDispatcherHeartbeat extends Model
{
    protected $fillable = [
        'dispatcher_key',
        'last_seen_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
