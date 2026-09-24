<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One query in a brand's demand table (see BrandDemandBuilder). Gold data: never deleted.
 */
class BrandDemandQuery extends Model
{
    public const string SOURCE_AUTO = 'auto';

    public const string SOURCE_OPERATOR = 'operator';

    protected $guarded = [];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    /** @return BelongsTo<BrandServiceArea, $this> */
    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(BrandServiceArea::class, 'brand_service_area_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'locations' => 'array',
            'sources' => 'array',
            'is_branded' => 'boolean',
            'ads_cost' => 'float',
            'ads_conversions' => 'float',
            'value_score' => 'float',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'built_at' => 'datetime',
        ];
    }
}
