<?php

namespace MoxDop\MetaAds\Models;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Meta `actions`/`action_values` row per raw action type per day.
 * Distinct raw_action_types are never blindly summed together (see
 * MoxDop\MetaAds\Normalization\MetaResultResolver::resultMix()).
 *
 * `attribution_window` defaults to '' (not null) so the unique constraint stays
 * idempotent on SQLite, which treats NULL as distinct in unique indexes.
 */
#[Fillable([
    'core_integration_id',
    'core_external_resource_id',
    'entity_type',
    'provider_external_id',
    'date',
    'raw_action_type',
    'normalized_family',
    'value',
    'action_value',
    'attribution_window',
    'provenance',
])]
class MetaAdsDailyAction extends Model
{
    protected $table = 'meta_ads_daily_actions';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        // Explicit format: the default 'date' cast serializes with the full
        // datetime grammar format on save, which would drift from the plain
        // 'Y-m-d' strings used by MetaHistoricalUpserter's raw updateOrCreate() keys.
        'date' => 'date:Y-m-d',
        'value' => 'float',
        'action_value' => 'float',
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
