<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceAutomation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'collection_enabled' => 'boolean', 'query_enabled' => 'boolean',
            'service_ids' => 'array', 'interval_days' => 'integer', 'revision' => 'integer',
            'next_collection_at' => 'immutable_datetime', 'collection_queued_at' => 'immutable_datetime',
            'last_collection_success_at' => 'immutable_datetime', 'last_query_success_at' => 'immutable_datetime',
            'query_checked_at' => 'immutable_datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class, 'external_resource_id');
    }
}
