<?php

namespace App\Models;

use App\Enums\OfferingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BrandOffering extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('visible_catalog', function ($query): void {
            $query->where(function ($scope): void {
                $scope->whereNull('brand_offerings.service_catalog_item_id')
                    ->orWhereHas('catalogItem');
            });
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'service_catalog_item_id',
        'status',
        'priority_rank',
        'is_priority',
        'priority',
        'locked',
    ];

    public const array PRIORITIES = ['main' => 'Ana', 'secondary' => 'İkincil'];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<ServiceCatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_catalog_item_id');
    }

    /**
     * @return HasMany<BrandOfferingName, $this>
     */
    public function names(): HasMany
    {
        return $this->hasMany(BrandOfferingName::class, 'brand_offering_id');
    }

    /**
     * @return HasOne<BrandOfferingName, $this>
     */
    public function primaryName(): HasOne
    {
        return $this->hasOne(BrandOfferingName::class, 'brand_offering_id')
            ->where('is_primary', true)
            ->where('is_active', true);
    }

    /**
     * @return BelongsToMany<BrandGoal, $this>
     */
    public function goals(): BelongsToMany
    {
        return $this->belongsToMany(
            BrandGoal::class,
            'brand_goal_offering',
            'brand_offering_id',
            'brand_goal_id'
        )->withTimestamps();
    }

    /** Operator-facing name: the brand's own primary name, else the catalog service's primary name. */
    public function displayName(): string
    {
        return (string) ($this->primaryName?->raw_label ?? $this->catalogItem?->primaryName?->raw_label ?? 'Hizmet #'.$this->id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OfferingStatus::class,
            'priority_rank' => 'integer',
            'is_priority' => 'boolean',
            'locked' => 'boolean',
        ];
    }
}
