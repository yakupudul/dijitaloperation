<?php

namespace App\Models;

use App\Enums\DigitalAssetStatus;
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
