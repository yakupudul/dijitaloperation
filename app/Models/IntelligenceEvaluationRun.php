<?php

namespace App\Models;

use App\Enums\IntelligenceEvaluationGateStatus;
use App\Enums\IntelligenceEvaluationLiveModelStatus;
use App\Enums\IntelligenceEvaluationRunMode;
use App\Enums\IntelligenceEvaluationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class IntelligenceEvaluationRun extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'suite_key',
        'suite_version',
        'dataset_key',
        'dataset_version',
        'evaluation_policy_version',
        'assertion_registry_version',
        'human_rubric_version',
        'run_mode',
        'status',
        'safety_gate_status',
        'quality_gate_status',
        'live_model_status',
        'agent_definition_signature',
        'skill_definition_signature',
        'ai_route_version',
        'retrieval_policy_version',
        'output_schema_version',
        'baseline_key',
        'baseline_run_id',
        'idempotency_key',
        'requested_by',
        'dimension_summary',
        'runtime_pins',
        'limits',
        'started_at',
        'finished_at',
    ];

    protected static function booting(): void
    {
        static::creating(function (self $run): void {
            if ($run->public_id === null || $run->public_id === '') {
                $run->public_id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return HasMany<IntelligenceEvaluationCaseRun, $this>
     */
    public function caseRuns(): HasMany
    {
        return $this->hasMany(IntelligenceEvaluationCaseRun::class, 'evaluation_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_mode' => IntelligenceEvaluationRunMode::class,
            'status' => IntelligenceEvaluationRunStatus::class,
            'safety_gate_status' => IntelligenceEvaluationGateStatus::class,
            'quality_gate_status' => IntelligenceEvaluationGateStatus::class,
            'live_model_status' => IntelligenceEvaluationLiveModelStatus::class,
            'dimension_summary' => 'array',
            'runtime_pins' => 'array',
            'limits' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
