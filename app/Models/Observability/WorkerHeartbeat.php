<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Model;

/**
 * Ephemeral infrastructure worker identity — not a business domain entity.
 */
class WorkerHeartbeat extends Model
{
    protected $fillable = [
        'worker_id',
        'supervisor',
        'queue_class',
        'hostname',
        'pid',
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
            'pid' => 'integer',
        ];
    }
}
