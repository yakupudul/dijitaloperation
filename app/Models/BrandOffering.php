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
    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'service_catalog_item_id',
        'status',
        'priority_rank',
    ];

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OfferingStatus::class,
            'priority_rank' => 'integer',
        ];
    }
}
