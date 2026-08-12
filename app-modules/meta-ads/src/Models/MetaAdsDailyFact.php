<?php

namespace MoxDop\MetaAds\Models;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One additive-metric daily fact row per (resource, entity_type, provider_external_id, date).
 *
 * `reach` and `frequency` are the provider's DAILY value for that day only — never sum
 * `reach` or average `frequency` across a date range. Use
 * MetaHistoricalQueryService::resolveReachFrequency() (exact period aggregate cache)
 * for range-level reach/frequency instead. A missing row/column means "not collected",
 * never a fabricated zero.
 */
#[Fillable([
    'core_integration_id',
    'core_external_resource_id',
    'entity_type',
    'provider_external_id',
    'parent_provider_external_id',
    'date',
    'spend',
    'impressions',
    'clicks',
    'link_clicks',
    'outbound_clicks',
    'reach',
    'frequency',
    'cpc',
    'cpm',
    'ctr',
    'link_ctr',
    'currency',
    'attribution_setting',
    'provenance',
])]
class MetaAdsDailyFact extends Model
{
    protected $table = 'meta_ads_daily_facts';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        // Explicit format: the default 'date' cast serializes with the full
        // datetime grammar format on save, which would drift from the plain
        // 'Y-m-d' strings used by MetaHistoricalUpserter's raw updateOrCreate() keys.
        'date' => 'date:Y-m-d',
        'spend' => 'float',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'link_clicks' => 'integer',
        'outbound_clicks' => 'integer',
        'reach' => 'integer',
        'frequency' => 'float',
        'cpc' => 'float',
        'cpm' => 'float',
        'ctr' => 'float',
        'link_ctr' => 'float',
        'provenance' => 'array',
    ];

    /**
     * @return BelongsTo<CoreIntegration, $this>
     */
    public function coreIntegration(): BelongsTo
    {
        return $this->belongsTo(CoreIntegration::class);
    }

    /**
     * @return BelongsTo<CoreExternalResource, $this>
     */
    public function coreExternalResource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class);
    }
}
