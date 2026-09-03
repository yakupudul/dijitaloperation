<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'brand_id', 'digital_asset_id', 'scope_type', 'scope_id', 'status', 'provider',
    'depth', 'device', 'include_query_expansion', 'location_code', 'location_name', 'language_code', 'language_name',
    'query_count', 'serp_cache_hits', 'metric_cache_hits', 'provider_request_count',
    'estimated_cost_usd', 'reported_cost_usd', 'cost_estimate_basis', 'request_context',
    'input_fingerprint', 'serp_batch_fingerprint', 'metric_batch_fingerprint', 'expansion_batch_fingerprint',
    'serp_paid_attempt_started_at', 'serp_committed_at', 'metric_paid_attempt_started_at',
    'metric_committed_at', 'expansion_paid_attempt_started_at', 'expansion_committed_at',
    'requested_by', 'paid_consent_recorded_at', 'started_at',
    'completed_at', 'failed_at', 'error_code', 'error_summary',
])]
class SearchDemandEnrichmentRun extends Model
{
    protected function casts(): array
    {
        return [
            'depth' => 'integer', 'include_query_expansion' => 'boolean', 'location_code' => 'integer', 'query_count' => 'integer',
            'serp_cache_hits' => 'integer', 'metric_cache_hits' => 'integer',
            'provider_request_count' => 'integer', 'estimated_cost_usd' => 'decimal:6',
            'reported_cost_usd' => 'decimal:6', 'cost_estimate_basis' => 'array',
            'request_context' => 'array', 'paid_consent_recorded_at' => 'immutable_datetime',
            'serp_paid_attempt_started_at' => 'immutable_datetime', 'serp_committed_at' => 'immutable_datetime',
            'metric_paid_attempt_started_at' => 'immutable_datetime', 'metric_committed_at' => 'immutable_datetime',
            'expansion_paid_attempt_started_at' => 'immutable_datetime', 'expansion_committed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function digitalAsset(): BelongsTo { return $this->belongsTo(DigitalAsset::class); }
    public function requestedBy(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function items(): HasMany { return $this->hasMany(SearchDemandEnrichmentRunItem::class); }
    public function clusterReviews(): HasMany { return $this->hasMany(SearchDemandSerpClusterReview::class); }
    public function expansionCandidates(): HasMany { return $this->hasMany(SearchDemandExpansionCandidate::class); }
    public function payloads(): HasMany { return $this->hasMany(SearchDemandProviderPayload::class); }
}
