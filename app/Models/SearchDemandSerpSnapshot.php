<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'search_demand_enrichment_run_id', 'brand_query_portfolio_item_id', 'digital_asset_id',
    'search_demand_cluster_id', 'query_text', 'provider', 'endpoint', 'request_fingerprint',
    'provider_task_id', 'location_code', 'location_name', 'language_code', 'language_name',
    'device', 'depth', 'result_count', 'serp_features', 'brand_rank', 'brand_url', 'retrieved_at',
])]
class SearchDemandSerpSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'location_code' => 'integer', 'depth' => 'integer', 'result_count' => 'integer',
            'serp_features' => 'array', 'brand_rank' => 'integer', 'retrieved_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandEnrichmentRun::class, 'search_demand_enrichment_run_id'); }
    public function portfolioItem(): BelongsTo { return $this->belongsTo(BrandQueryPortfolioItem::class, 'brand_query_portfolio_item_id'); }
    public function cluster(): BelongsTo { return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id'); }
    public function results(): HasMany { return $this->hasMany(SearchDemandSerpResult::class)->orderBy('rank_group'); }
}
