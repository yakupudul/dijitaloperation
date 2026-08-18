<?php

namespace App\Models;

use App\Enums\Intelligence\IntelligencePlanPhase;
use App\Enums\Intelligence\IntelligencePlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable intelligence execution plan (Prompt 63).
 */
class IntelligenceExecutionPlan extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'brand_id',
        'digital_asset_id',
        'intelligence_trigger_id',
        'plan_fingerprint',
        'status',
        'current_phase',
        'trigger_ids',
        'evidence_input_fingerprints',
        'analyzers',
        'phase_results',
        'metadata',
        'supersedes_plan_id',
        'created_at',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntelligencePlanStatus::class,
            'current_phase' => IntelligencePlanPhase::class,
            'trigger_ids' => 'array',
            'evidence_input_fingerprints' => 'array',
            'analyzers' => 'array',
            'phase_results' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(IntelligenceTrigger::class, 'intelligence_trigger_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            IntelligencePlanStatus::Completed,
            IntelligencePlanStatus::Failed,
            IntelligencePlanStatus::Blocked,
            IntelligencePlanStatus::Coalesced,
            IntelligencePlanStatus::Superseded,
            IntelligencePlanStatus::NoRelevantAnalyzer,
        ], true);
    }
}
