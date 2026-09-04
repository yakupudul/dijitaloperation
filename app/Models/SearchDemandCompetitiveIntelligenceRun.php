<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'run_id', 'brand_id', 'digital_asset_id', 'search_demand_cluster_id',
    'search_demand_page_ownership_id', 'status', 'input_payload', 'response_payload',
    'input_fingerprint', 'agent_signature', 'skill_signature', 'skill_fingerprint',
    'route_key', 'route_signature', 'provider', 'model', 'page_count', 'summary',
    'portfolio_gap_themes', 'differentiation_strategy', 'caveats', 'confidence',
    'abstained', 'abstention_reason', 'requested_by', 'started_at', 'completed_at',
    'failed_at', 'error_code', 'error_summary',
])]
final class SearchDemandCompetitiveIntelligenceRun extends Model
{
    protected function casts(): array
    {
        return [
            'input_payload' => 'array', 'response_payload' => 'array',
            'portfolio_gap_themes' => 'array', 'differentiation_strategy' => 'array', 'caveats' => 'array',
            'page_count' => 'integer', 'confidence' => 'integer', 'abstained' => 'boolean',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function activityRun(): BelongsTo { return $this->belongsTo(Run::class, 'run_id'); }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function website(): BelongsTo { return $this->belongsTo(DigitalAsset::class, 'digital_asset_id'); }
    public function cluster(): BelongsTo { return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id'); }
    public function ownership(): BelongsTo { return $this->belongsTo(SearchDemandPageOwnership::class, 'search_demand_page_ownership_id'); }
    public function analyses(): HasMany { return $this->hasMany(SearchDemandCompetitivePageAnalysis::class, 'competitive_intelligence_run_id'); }
}
