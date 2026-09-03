<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_cluster_id',
    'brand_query_portfolio_item_id',
    'source',
    'confidence',
    'rationale',
    'assigned_version',
    'assigned_by',
])]
class SearchDemandClusterMembership extends Model
{
    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'assigned_version' => 'integer',
        ];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id');
    }

    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(BrandQueryPortfolioItem::class, 'brand_query_portfolio_item_id');
    }
}
