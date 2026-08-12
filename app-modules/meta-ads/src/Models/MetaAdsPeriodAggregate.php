<?php

namespace MoxDop\MetaAds\Models;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exact provider period cache for non-additive metrics (reach, frequency) that must
 * never be derived by summing/averaging `meta_ads_daily_facts` rows. One row per
 * (resource, entity, exact date range, attribution context, metric).
 */
#[Fillable([
    'core_integration_id',
    'core_external_resource_id',
    'entity_type',
    'provider_external_id',
    'date_from',
    'date_to',
    'attribution_context',
    'metric_key',
    'metric_value',
    'status',
    'provenance',
    'run_id',
    'fetched_at',
])]
class MetaAdsPeriodAggregate extends Model
{
    public const string STATUS_READY = 'ready';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_UNAVAILABLE = 'unavailable';

    public const string STATUS_FAILED = 'failed';

    public const string METRIC_REACH = 'reach';

    public const string METRIC_FREQUENCY = 'frequency';

    public const string ATTRIBUTION_CONTEXT_UNIFIED = 'unified';

    protected $table = 'meta_ads_period_aggregates';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        // Explicit format keeps saved values matching the plain 'Y-m-d' strings used
        // by MetaHistoricalUpserter's raw updateOrCreate() keys (see MetaAdsDailyFact).
        'date_from' => 'date:Y-m-d',
        'date_to' => 'date:Y-m-d',
        'metric_value' => 'float',
        'provenance' => 'array',
        'fetched_at' => 'datetime',
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

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
