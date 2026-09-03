<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_enrichment_run_id', 'brand_query_portfolio_item_id', 'search_demand_cluster_id',
    'query_text', 'serp_request_fingerprint', 'metric_request_fingerprint', 'serp_status',
    'metric_status', 'serp_paid_attempt_started_at', 'serp_committed_at', 'serp_reported_cost_usd',
    'serp_snapshot_id', 'keyword_metric_snapshot_id', 'error_summary',
])]
class SearchDemandEnrichmentRunItem extends Model
{
    protected function casts(): array
    {
        return [
            'serp_paid_attempt_started_at' => 'immutable_datetime',
            'serp_committed_at' => 'immutable_datetime',
            'serp_reported_cost_usd' => 'decimal:6',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandEnrichmentRun::class, 'search_demand_enrichment_run_id'); }
    public function portfolioItem(): BelongsTo { return $this->belongsTo(BrandQueryPortfolioItem::class, 'brand_query_portfolio_item_id'); }
    public function cluster(): BelongsTo { return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id'); }
    public function serpSnapshot(): BelongsTo { return $this->belongsTo(SearchDemandSerpSnapshot::class, 'serp_snapshot_id'); }
    public function keywordMetricSnapshot(): BelongsTo { return $this->belongsTo(SearchDemandKeywordMetricSnapshot::class, 'keyword_metric_snapshot_id'); }
}
