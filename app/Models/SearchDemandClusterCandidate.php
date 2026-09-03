<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_clustering_run_id',
    'action_type',
    'existing_cluster_id',
    'source_cluster_ids',
    'member_portfolio_item_ids',
    'candidate_fingerprint',
    'cluster_key',
    'cluster_name',
    'demand_family',
    'serp_intent_group',
    'content_target_cluster',
    'representative_portfolio_item_id',
    'suggested_content_type',
    'confidence',
    'uncertain',
    'uncertainty_reason',
    'rationale',
    'status',
    'approved_cluster_id',
    'reviewed_by',
    'reviewed_at',
    'raw_output',
])]
class SearchDemandClusterCandidate extends Model
{
    protected function casts(): array
    {
        return [
            'source_cluster_ids' => 'array',
            'member_portfolio_item_ids' => 'array',
            'confidence' => 'integer',
            'uncertain' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
            'raw_output' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SearchDemandClusteringRun::class, 'search_demand_clustering_run_id');
    }

    public function existingCluster(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCluster::class, 'existing_cluster_id');
    }

    public function approvedCluster(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCluster::class, 'approved_cluster_id');
    }
}
