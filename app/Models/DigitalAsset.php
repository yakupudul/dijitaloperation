<?php

namespace App\Models;

use App\Enums\DigitalAssetStatus;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsiteEntityProfile;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsiteOutcomeProfile;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\IntelligenceProjection\WebsiteSearchTermProfile;
use Database\Factories\DigitalAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'brand_id',
    'name',
    'type',
    'status',
    'module_id',
    'domain',
    'primary_url',
    'cms',
    'languages',
    'target_countries',
    'seo_market_location_code',
    'seo_market_location_name',
    'seo_market_language_code',
    'seo_market_language_name',
    'site_type',
    'hosting_context',
])]
class DigitalAsset extends Model
{
    /** @use HasFactory<DigitalAssetFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<CoreConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(CoreConnection::class);
    }

    /**
     * Provider External Resource bindings (agency Integration credentials are not stored here).
     *
     * @return HasMany<CoreAssetBinding, $this>
     */
    public function assetBindings(): HasMany
    {
        return $this->hasMany(CoreAssetBinding::class);
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * Provider-neutral Page identities resolved for this Website asset.
     *
     * @return HasMany<IntelligencePageIdentity, $this>
     */
    public function intelligencePageIdentities(): HasMany
    {
        return $this->hasMany(IntelligencePageIdentity::class, 'website_asset_id');
    }

    /** @return HasMany<WebsiteIntelligenceProjectionRun, $this> */
    public function websiteProjectionRuns(): HasMany
    {
        return $this->hasMany(WebsiteIntelligenceProjectionRun::class, 'website_asset_id');
    }

    /** @return HasMany<WebsitePageProfile, $this> */
    public function websitePageProfiles(): HasMany
    {
        return $this->hasMany(WebsitePageProfile::class, 'website_asset_id');
    }

    /** @return HasMany<WebsiteSearchTermProfile, $this> */
    public function websiteSearchTermProfiles(): HasMany
    {
        return $this->hasMany(WebsiteSearchTermProfile::class, 'website_asset_id');
    }

    /** @return HasMany<WebsiteEntityProfile, $this> */
    public function websiteEntityProfiles(): HasMany
    {
        return $this->hasMany(WebsiteEntityProfile::class, 'website_asset_id');
    }

    /** @return HasMany<WebsiteOutcomeProfile, $this> */
    public function websiteOutcomeProfiles(): HasMany
    {
        return $this->hasMany(WebsiteOutcomeProfile::class, 'website_asset_id');
    }

    /** @return HasMany<BrandQueryPortfolioAsset, $this> */
    public function queryPortfolioStates(): HasMany
    {
        return $this->hasMany(BrandQueryPortfolioAsset::class);
    }

    /** @return HasMany<SearchDemandPageOwnership, $this> */
    public function searchDemandPageOwnerships(): HasMany
    {
        return $this->hasMany(SearchDemandPageOwnership::class);
    }

    /** @return HasMany<SearchDemandPageRelevanceRun, $this> */
    public function searchDemandPageRelevanceRuns(): HasMany
    {
        return $this->hasMany(SearchDemandPageRelevanceRun::class);
    }

    /**
     * @return HasMany<Recommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DigitalAssetStatus::class,
            'languages' => 'array',
            'target_countries' => 'array',
            'seo_market_location_code' => 'integer',
        ];
    }

    public function hasSeoMarketConfigured(): bool
    {
        return $this->seo_market_location_code !== null
            && filled($this->seo_market_language_code);
    }
}
