<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $brand_id
 * @property string $source
 * @property string $source_key
 * @property string $label
 * @property string $conversion_type
 * @property bool $counts
 * @property string $origin
 */
class BrandConversionSource extends Model
{
    public const string SOURCE_GA4 = 'ga4_key_event';

    public const string SOURCE_GOOGLE_ADS = 'google_ads_conversion_action';

    public const string SOURCE_META = 'meta_action';

    public const string SOURCE_GBP = 'gbp_metric';

    public const string ORIGIN_AUTO = 'auto';

    public const string ORIGIN_OPERATOR = 'operator';

    protected $fillable = ['brand_id', 'source', 'source_key', 'label', 'conversion_type', 'counts', 'origin', 'metadata', 'last_seen_at'];

    protected function casts(): array
    {
        return ['counts' => 'boolean', 'metadata' => 'array', 'last_seen_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
