<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_enrichment_run_id', 'source_request_fingerprint', 'candidate_fingerprint',
    'keyword', 'search_volume', 'cpc', 'competition', 'competition_index', 'monthly_searches',
    'measurement_type', 'status', 'approved_portfolio_item_id', 'reviewed_by', 'reviewed_at',
])]
class SearchDemandExpansionCandidate extends Model
{
    protected function casts(): array
    {
        return [
            'search_volume' => 'integer', 'cpc' => 'decimal:6', 'competition_index' => 'integer',
            'monthly_searches' => 'array', 'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandEnrichmentRun::class, 'search_demand_enrichment_run_id'); }
    public function approvedPortfolioItem(): BelongsTo { return $this->belongsTo(BrandQueryPortfolioItem::class, 'approved_portfolio_item_id'); }
}
