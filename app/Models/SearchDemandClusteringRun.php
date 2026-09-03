<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'brand_id',
    'mode',
    'status',
    'input_payload',
    'input_fingerprint',
    'agent_signature',
    'skill_signature',
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
    'requested_by',
    'started_at',
    'completed_at',
    'failed_at',
    'error_code',
    'error_summary',
])]
class SearchDemandClusteringRun extends Model
{
    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
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

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(SearchDemandClusterCandidate::class);
    }
}
