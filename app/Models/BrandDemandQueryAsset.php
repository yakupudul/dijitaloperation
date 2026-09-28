<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site-specific Search Console metrics of one hub query (a brand may have several websites).
 */
class BrandDemandQueryAsset extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<BrandDemandQuery, $this> */
    public function demandQuery(): BelongsTo
    {
        return $this->belongsTo(BrandDemandQuery::class, 'brand_demand_query_id');
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gsc_position' => 'float',
            'first_observed_on' => 'date',
            'last_observed_on' => 'date',
            'built_at' => 'datetime',
        ];
    }
}
