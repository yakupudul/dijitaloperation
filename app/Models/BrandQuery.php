<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand layer: one normalized query targeted by one brand, optionally for one service area (physical branch or not),
 * with its language, the URL that ranks for it and the last-28-day performance.
 */
class BrandQuery extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'query_id',
        'target_area_id',
        'language',
        'url',
        'clicks_28d',
        'impressions_28d',
        'position_28d',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'clicks_28d' => 'integer',
            'impressions_28d' => 'integer',
            'position_28d' => 'float',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Query, $this> */
    public function searchQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<BrandServiceArea, $this> */
    public function targetArea(): BelongsTo
    {
        return $this->belongsTo(BrandServiceArea::class, 'target_area_id');
    }
}
