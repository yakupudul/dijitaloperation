<?php

namespace App\Models\IntelligenceProjection;

use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'website_asset_id',
    'trigger_collection_run_id',
    'trigger',
    'status',
    'schema_version',
    'intelligence_registry_version',
    'period_start',
    'period_end',
    'source_watermarks',
    'coverage_state',
    'summary',
    'started_at',
    'completed_at',
    'error_code',
    'error_summary',
])]
final class WebsiteIntelligenceProjectionRun extends Model
{
    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_PARTIAL = 'partial';

    public const string STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'intelligence_registry_version' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'source_watermarks' => 'array',
            'coverage_state' => 'array',
            'summary' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function websiteAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }

    /** @return BelongsTo<CollectionRun, $this> */
    public function triggerCollectionRun(): BelongsTo
    {
        return $this->belongsTo(CollectionRun::class, 'trigger_collection_run_id');
    }
}
