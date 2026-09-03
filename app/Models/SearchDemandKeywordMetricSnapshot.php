<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_enrichment_run_id', 'brand_query_portfolio_item_id', 'digital_asset_id',
    'query_text', 'provider', 'endpoint', 'request_fingerprint', 'provider_task_id',
    'location_code', 'language_code', 'search_volume', 'cpc', 'competition',
    'competition_index', 'monthly_searches', 'measurement_type', 'retrieved_at',
])]
class SearchDemandKeywordMetricSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'location_code' => 'integer', 'search_volume' => 'integer', 'cpc' => 'decimal:6',
            'competition_index' => 'integer', 'monthly_searches' => 'array', 'retrieved_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandEnrichmentRun::class, 'search_demand_enrichment_run_id'); }
    public function portfolioItem(): BelongsTo { return $this->belongsTo(BrandQueryPortfolioItem::class, 'brand_query_portfolio_item_id'); }
}
