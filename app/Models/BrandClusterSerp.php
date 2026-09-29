<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rakipler screen table (written by the competitor refresh, read by the screen): one brand cluster on one website — the
 * representative query, target location / language / device, our rank, the top-10 with each domain's class and the
 * last competitor analysis.
 */
class BrandClusterSerp extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'brand_id', 'cluster_id', 'website_asset_id', 'query', 'location_code', 'language_code', 'device', 'own_rank',
        'results', 'status', 'error', 'analysis', 'fetched_at', 'analyzed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'results' => 'array',
            'analysis' => 'array',
            'own_rank' => 'integer',
            'location_code' => 'integer',
            'fetched_at' => 'immutable_datetime',
            'analyzed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }
}
