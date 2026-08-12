<?php

namespace MoxDop\MetaAds\Models;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authoritative per-account import state for a Meta Ad Account's history import.
 *
 * One row per discovered available meta_ads CoreExternalResource under an Integration.
 * The single source of truth for account-level progress and the parent Run's derived
 * status — Run metadata counts have historically drifted, so this table (not metadata)
 * backs the operator-visible "N / M accounts ready" label. Never invents accounts.
 */
#[Fillable([
    'core_integration_id',
    'core_external_resource_id',
    'status',
    'phase_label',
    'earliest_date',
    'latest_date',
    'campaigns_total',
    'campaigns_done',
    'adsets_total',
    'adsets_done',
    'ads_total',
    'ads_done',
    'chunks_total',
    'chunks_done',
    'daily_facts_count',
    'last_error_category',
    'last_error_summary',
    'last_import_run_id',
    'last_successful_at',
])]
class MetaAdsAccountImportState extends Model
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_DISCOVERING = 'discovering';

    public const string STATUS_FETCHING_METADATA = 'fetching_metadata';

    public const string STATUS_PREPARING_INSIGHTS = 'preparing_insights';

    public const string STATUS_WAITING_REPORT = 'waiting_report';

    public const string STATUS_DOWNLOADING = 'downloading';

    public const string STATUS_NORMALIZING = 'normalizing';

    public const string STATUS_READY = 'ready';

    public const string STATUS_PARTIAL = 'partial';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const string STATUS_WAITING = 'waiting';

    /**
     * Statuses that represent active in-flight work (not terminal, not idle).
     *
     * @var list<string>
     */
    public const array RUNNING_STATUSES = [
        self::STATUS_DOWNLOADING,
        self::STATUS_NORMALIZING,
        self::STATUS_FETCHING_METADATA,
        self::STATUS_PREPARING_INSIGHTS,
        self::STATUS_WAITING_REPORT,
        self::STATUS_DISCOVERING,
    ];

    /**
     * Terminal statuses — an account will not transition out of these without a new run.
     *
     * @var list<string>
     */
    public const array TERMINAL_STATUSES = [
        self::STATUS_READY,
        self::STATUS_PARTIAL,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_ATTENTION,
    ];

    protected $table = 'meta_ads_account_import_states';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'earliest_date' => 'date:Y-m-d',
        'latest_date' => 'date:Y-m-d',
        'campaigns_total' => 'integer',
        'campaigns_done' => 'integer',
        'adsets_total' => 'integer',
        'adsets_done' => 'integer',
        'ads_total' => 'integer',
        'ads_done' => 'integer',
        'chunks_total' => 'integer',
        'chunks_done' => 'integer',
        'daily_facts_count' => 'integer',
        'last_successful_at' => 'datetime',
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
    public function lastImportRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'last_import_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING_STATUSES, true);
    }
}
