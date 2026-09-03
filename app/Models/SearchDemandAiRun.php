<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'operation_type',
    'service_catalog_item_id',
    'status',
    'input_payload',
    'input_fingerprint',
    'agent_signature',
    'skill_signatures',
    'skill_fingerprint',
    'route_key',
    'route_signature',
    'provider',
    'model',
    'total_candidates',
    'pending_candidates',
    'approved_candidates',
    'rejected_candidates',
    'abstained',
    'abstention_reason',
    'reused_from_run_id',
    'requested_by',
    'started_at',
    'completed_at',
    'failed_at',
    'error_code',
    'error_summary',
])]
class SearchDemandAiRun extends Model
{
    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'skill_signatures' => 'array',
            'abstained' => 'boolean',
            'total_candidates' => 'integer',
            'pending_candidates' => 'integer',
            'approved_candidates' => 'integer',
            'rejected_candidates' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_catalog_item_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(SearchDemandAiCandidate::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reusedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reused_from_run_id');
    }
}
