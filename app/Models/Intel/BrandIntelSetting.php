<?php

namespace App\Models\Intel;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-brand opt-in for paid market intelligence (Faz 8): map grid, reviews, backlinks, under one monthly USD cap.
 */
class BrandIntelSetting extends Model
{
    protected $fillable = [
        'brand_id', 'monthly_usd', 'grid_enabled', 'grid_keywords', 'grid_center_lat', 'grid_center_lng', 'grid_size',
        'grid_spacing_km', 'grid_every_days', 'gbp_place_id', 'gbp_cid', 'reviews_enabled', 'backlinks_enabled', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'monthly_usd' => 'float',
            'grid_enabled' => 'boolean',
            'grid_keywords' => 'array',
            'grid_center_lat' => 'float',
            'grid_center_lng' => 'float',
            'grid_size' => 'integer',
            'grid_spacing_km' => 'float',
            'grid_every_days' => 'integer',
            'reviews_enabled' => 'boolean',
            'backlinks_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public static function for(Brand $brand): self
    {
        return self::query()->firstOrNew(['brand_id' => $brand->id], [
            'monthly_usd' => (float) config('moxdop-intel.default_monthly_usd', 5),
            'grid_size' => (int) config('moxdop-intel.grid.default_size', 7),
            'grid_spacing_km' => (float) config('moxdop-intel.grid.default_spacing_km', 1),
            'grid_every_days' => (int) config('moxdop-intel.grid.default_every_days', 7),
            'grid_keywords' => [],
        ]);
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return array_values(array_filter(array_map(static fn ($k): string => trim((string) $k), (array) $this->grid_keywords), static fn (string $k): bool => $k !== ''));
    }
}
