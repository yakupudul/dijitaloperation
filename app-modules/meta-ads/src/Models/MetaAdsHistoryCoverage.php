<?php

namespace MoxDop\MetaAds\Models;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks what has actually been imported for a Meta Ad Account, separate from fact
 * absence — a missing `meta_ads_daily_facts` row for a date can mean "no delivery" OR
 * "not imported yet"; this table disambiguates via coverage status/date bounds.
 */
#[Fillable([
    'core_integration_id',
    'core_external_resource_id',
    'data_layer',
    'granularity',
    'start_date',
    'end_date',
    'status',
    'last_successful_sync_at',
    'earliest_provider_date',
    'latest_provider_date',
    'gaps',
    'import_run_id',
    'summary',
])]
class MetaAdsHistoryCoverage extends Model
{
    public const string LAYER_ENTITIES = 'entities';

    public const string LAYER_DAILY_FACTS = 'daily_facts';

    public const string LAYER_DAILY_ACTIONS = 'daily_actions';

    public const string LAYER_PERIOD_AGGREGATES = 'period_aggregates';

    public const string STATUS_NOT_IMPORTED = 'not_imported';

    public const string STATUS_IMPORTING = 'importing';

    public const string STATUS_PARTIAL = 'partial';

    public const string STATUS_COMPLETE = 'complete';

    public const string STATUS_OUTSIDE_PROVIDER = 'outside_provider';

    protected $table = 'meta_ads_history_coverage';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        // Explicit format keeps saved values matching the plain 'Y-m-d' strings used
        // by MetaHistoricalUpserter's raw updateOrCreate()/firstOrNew() keys.
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'last_successful_sync_at' => 'datetime',
        'earliest_provider_date' => 'date:Y-m-d',
        'latest_provider_date' => 'date:Y-m-d',
        'gaps' => 'array',
        'summary' => 'array',
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
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'import_run_id');
    }
}
