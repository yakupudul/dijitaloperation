<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\IntelligenceCore\IntelligenceBusinessActionIdentity;
use App\Models\IntelligenceCore\IntelligenceEntityIdentity;
use App\Models\IntelligenceCore\IntelligenceSearchTermIdentity;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'customer_id',
    'name',
    'sector',
    'sector_id',
    'primary_country',
    'target_markets',
    'languages',
    'description',
    'audience',
    'offerings',
    'competitors',
    'logo_url',
])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Service scope, the brand-level twin of DigitalAsset::operational(): a brand is served (collected, analysed,
     * AI / paid providers called, work shown) only while its customer is active. Brands have no status of their
     * own; a deleted brand is excluded by SoftDeletes.
     *
     * @param  Builder<Brand>  $query
     * @return Builder<Brand>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereHas('customer', fn (Builder $customer): Builder => $customer->where('status', CustomerStatus::Active->value));
    }

    public function isOperational(): bool
    {
        return ! $this->trashed() && $this->customer?->status === CustomerStatus::Active;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function responsibleUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<DigitalAsset, $this>
     */
    public function digitalAssets(): HasMany
    {
        return $this->hasMany(DigitalAsset::class);
    }

    /**
     * Structured factual Brand Intelligence Context (optional one-to-one).
     *
     * @return HasOne<BrandIntelligenceContext, $this>
     */
    public function intelligenceContext(): HasOne
    {
        return $this->hasOne(BrandIntelligenceContext::class);
    }

    /**
     * @return HasMany<BrandGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(BrandGoal::class);
    }

    /**
     * @return HasMany<BrandOffering, $this>
     */
    public function offerings(): HasMany
    {
        return $this->hasMany(BrandOffering::class);
    }

    /**
     * v2 (Faz 2): the brand's ONE sector — `brands.sector_id` is the only place a sector is stored; assets read it
     * through their brand. The legacy code column `sector` is a mirror kept in sync on save (read-only for callers).
     *
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function sectorCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'sector_id');
    }

    /**
     * Deprecated (v2): the former multi-sector pivot. Not read; kept only so old rows can be inspected.
     *
     * @return BelongsToMany<ServiceCategory, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'brand_service_category')->withTimestamps();
    }

    /** @return list<string> the sector code of `sector_id` (one sector per brand), or none */
    public function sectorCodes(): array
    {
        $code = $this->sectorCategory?->code;

        return $code !== null && $code !== '' ? [(string) $code] : [];
    }

    public function sectorLabel(): ?string
    {
        return $this->sectorCategory?->name;
    }

    protected static function booted(): void
    {
        // One truth: sector_id. A caller that still sets the legacy code gets the matching id; the code mirrors the id.
        static::saving(function (Brand $brand): void {
            if ($brand->isDirty('sector_id')) {
                $brand->attributes['sector'] = $brand->sector_id !== null
                    ? ServiceCategory::query()->whereKey($brand->sector_id)->value('code')
                    : null;
            } elseif ($brand->isDirty('sector')) {
                $brand->attributes['sector_id'] = filled($brand->sector)
                    ? ServiceCategory::query()->where('code', (string) $brand->sector)->value('id')
                    : null;
            }
        });
    }

    /** @return HasMany<BrandServiceArea, $this> */
    public function serviceAreas(): HasMany
    {
        return $this->hasMany(BrandServiceArea::class);
    }

    /** @return HasMany<IntelligenceSearchTermIdentity, $this> */
    public function intelligenceSearchTerms(): HasMany
    {
        return $this->hasMany(IntelligenceSearchTermIdentity::class);
    }

    /** @return HasMany<IntelligenceEntityIdentity, $this> */
    public function intelligenceEntities(): HasMany
    {
        return $this->hasMany(IntelligenceEntityIdentity::class);
    }

    /** @return HasMany<IntelligenceBusinessActionIdentity, $this> */
    public function intelligenceBusinessActions(): HasMany
    {
        return $this->hasMany(IntelligenceBusinessActionIdentity::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_markets' => 'array',
            'languages' => 'array',
            'demand_serp_enabled' => 'boolean',
            'demand_serp_monthly_usd' => 'float',
        ];
    }
}
