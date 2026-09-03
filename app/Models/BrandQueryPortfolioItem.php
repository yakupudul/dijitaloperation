<?php

namespace App\Models;

use App\Models\IntelligenceCore\IntelligenceSearchTermIdentity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'uuid',
    'brand_id',
    'search_query_library_item_id',
    'intelligence_search_term_identity_id',
    'identity_hash',
    'custom_canonical_text',
    'custom_folded_text',
    'query_text_override',
    'language_code',
    'market_code',
    'demand_family_override',
    'location_scope_override',
    'location_value_override',
    'is_branded_override',
    'area_scope',
    'origin_type',
    'status',
    'global_proposal_status',
    'global_proposed_at',
    'global_proposed_by',
    'created_by',
    'updated_by',
])]
class BrandQueryPortfolioItem extends Model
{
    protected function casts(): array
    {
        return [
            'is_branded_override' => 'boolean',
            'global_proposed_at' => 'immutable_datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(SearchQueryLibraryItem::class, 'search_query_library_item_id');
    }

    public function intelligenceIdentity(): BelongsTo
    {
        return $this->belongsTo(IntelligenceSearchTermIdentity::class, 'intelligence_search_term_identity_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceCatalogItem::class,
            'brand_query_portfolio_item_service',
        )->withPivot('provenance')->withTimestamps();
    }

    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(
            BrandServiceArea::class,
            'brand_query_portfolio_item_area',
        )->withTimestamps();
    }

    public function assetStates(): HasMany
    {
        return $this->hasMany(BrandQueryPortfolioAsset::class);
    }

    public function clusterMembership(): HasOne
    {
        return $this->hasOne(SearchDemandClusterMembership::class);
    }

    public function effectiveQueryText(): string
    {
        return $this->query_text_override
            ?: ($this->libraryItem?->canonical_text ?: (string) $this->custom_canonical_text);
    }

    public function effectiveDemandFamily(): ?string
    {
        return $this->demand_family_override ?: $this->libraryItem?->demand_family;
    }

    public function effectiveLocationScope(): string
    {
        return $this->location_scope_override
            ?: ($this->libraryItem?->location_scope ?: 'none');
    }

    public function effectiveLocationValue(): ?string
    {
        return $this->location_value_override ?: $this->libraryItem?->location_value;
    }

    public function effectiveLanguageCode(): ?string
    {
        return $this->language_code ?: $this->libraryItem?->language_code;
    }

    public function effectiveMarketCode(): ?string
    {
        return $this->market_code ?: $this->libraryItem?->market_code;
    }

    public function effectiveIsBranded(): bool
    {
        return $this->is_branded_override ?? (bool) $this->libraryItem?->is_branded;
    }
}
