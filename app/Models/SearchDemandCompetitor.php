<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'brand_id', 'display_name', 'normalized_domain', 'normalized_domain_hash', 'status',
    'entity_kind', 'is_commercial_competitor', 'is_serp_competitor', 'is_content_competitor',
    'notes', 'first_observed_at', 'last_observed_at', 'reviewed_by', 'reviewed_at', 'created_by',
    'updated_by',
])]
class SearchDemandCompetitor extends Model
{
    /** @var list<string> */
    public const array ENTITY_KINDS = ['unknown', 'business', 'directory', 'platform', 'authority'];

    protected function casts(): array
    {
        return [
            'is_commercial_competitor' => 'boolean',
            'is_serp_competitor' => 'boolean',
            'is_content_competitor' => 'boolean',
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(SearchDemandCompetitorSource::class);
    }

    public function urls(): HasMany
    {
        return $this->hasMany(SearchDemandCompetitorUrl::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCatalogItem::class, 'search_demand_competitor_service')
            ->withPivot('provenance')->withTimestamps();
    }

    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(BrandServiceArea::class, 'search_demand_competitor_area')
            ->withPivot('provenance')->withTimestamps();
    }

    public function clusters(): BelongsToMany
    {
        return $this->belongsToMany(SearchDemandCluster::class, 'search_demand_competitor_cluster')
            ->withPivot('provenance')->withTimestamps();
    }

    public function queries(): BelongsToMany
    {
        return $this->belongsToMany(BrandQueryPortfolioItem::class, 'search_demand_competitor_queries')
            ->withPivot(['source_type', 'best_observed_rank', 'first_observed_at', 'last_observed_at'])
            ->withTimestamps();
    }
}
