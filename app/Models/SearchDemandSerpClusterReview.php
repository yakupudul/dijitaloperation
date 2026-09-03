<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_enrichment_run_id', 'search_demand_cluster_id', 'evidence_query_count',
    'compared_pair_count', 'mean_url_overlap', 'recommended_status', 'threshold_basis',
    'rationale', 'status', 'reviewed_by', 'reviewed_at',
])]
class SearchDemandSerpClusterReview extends Model
{
    protected function casts(): array
    {
        return [
            'evidence_query_count' => 'integer', 'compared_pair_count' => 'integer',
            'mean_url_overlap' => 'decimal:6', 'threshold_basis' => 'array', 'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandEnrichmentRun::class, 'search_demand_enrichment_run_id'); }
    public function cluster(): BelongsTo { return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
