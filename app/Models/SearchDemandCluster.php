<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'brand_id',
    'cluster_key',
    'name',
    'demand_family',
    'serp_intent_group',
    'content_target_cluster',
    'representative_portfolio_item_id',
    'suggested_content_type',
    'rationale',
    'confidence',
    'validation_status',
    'is_locked',
    'version',
    'status',
    'merged_into_cluster_id',
    'last_clustered_at',
    'created_by',
    'updated_by',
])]
class SearchDemandCluster extends Model
{
    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'is_locked' => 'boolean',
            'version' => 'integer',
            'last_clustered_at' => 'immutable_datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function representativeItem(): BelongsTo
    {
        return $this->belongsTo(BrandQueryPortfolioItem::class, 'representative_portfolio_item_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SearchDemandClusterMembership::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SearchDemandClusterVersion::class);
    }

    public function pageOwnerships(): HasMany
    {
        return $this->hasMany(SearchDemandPageOwnership::class);
    }

    public function pageRelevanceRuns(): HasMany
    {
        return $this->hasMany(SearchDemandPageRelevanceRun::class);
    }

    public function competitors(): BelongsToMany
    {
        return $this->belongsToMany(SearchDemandCompetitor::class, 'search_demand_competitor_cluster')
            ->withPivot('provenance')->withTimestamps();
    }

    public function competitorPageRunItems(): HasMany
    {
        return $this->hasMany(SearchDemandCompetitorPageRunItem::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_cluster_id');
    }
}
