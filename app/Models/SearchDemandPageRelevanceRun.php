<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'brand_id', 'digital_asset_id', 'search_demand_cluster_id', 'status', 'period_start',
    'period_end', 'comparison_start', 'comparison_end', 'input_payload', 'response_payload', 'input_fingerprint',
    'agent_signature', 'skill_signature', 'skill_fingerprint', 'route_key', 'route_signature',
    'provider', 'model', 'deterministic_state', 'ai_decision_state', 'wrong_url_candidate', 'cannibalization_candidate',
    'recommended_content_type', 'recommended_candidate_id', 'candidate_count', 'eligible_candidate_count',
    'abstained', 'abstention_reason', 'rationale', 'requested_by', 'started_at', 'completed_at',
    'failed_at', 'error_code', 'error_summary',
])]
class SearchDemandPageRelevanceRun extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date', 'period_end' => 'immutable_date',
            'comparison_start' => 'immutable_date', 'comparison_end' => 'immutable_date',
            'input_payload' => 'array', 'response_payload' => 'array', 'wrong_url_candidate' => 'boolean',
            'cannibalization_candidate' => 'boolean', 'candidate_count' => 'integer',
            'eligible_candidate_count' => 'integer', 'abstained' => 'boolean',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function website(): BelongsTo { return $this->belongsTo(DigitalAsset::class, 'digital_asset_id'); }
    public function cluster(): BelongsTo { return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id'); }
    public function candidates(): HasMany { return $this->hasMany(SearchDemandPageCandidate::class); }
    public function recommendedCandidate(): BelongsTo { return $this->belongsTo(SearchDemandPageCandidate::class, 'recommended_candidate_id'); }
}
