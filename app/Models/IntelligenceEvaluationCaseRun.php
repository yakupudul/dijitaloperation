<?php

namespace App\Models;

use App\Enums\IntelligenceEvaluationAblationVariant;
use App\Enums\IntelligenceEvaluationGateStatus;
use App\Enums\IntelligenceEvaluationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntelligenceEvaluationCaseRun extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'evaluation_run_id',
        'case_key',
        'case_version',
        'dataset_version',
        'status',
        'safety_gate_status',
        'ablation_variant',
        'eval_customer_id',
        'eval_brand_id',
        'retrieval_fingerprint',
        'context_pack_fingerprint',
        'agent_execution_run_id',
        'retrieval_metrics',
        'dimension_results',
        'runtime_pins',
        'mocked_output',
        'retrieval_duration_ms',
        'provider_latency_ms',
        'input_tokens',
        'output_tokens',
        'attempt_count',
        'failure_summary',
    ];

    /**
     * @return BelongsTo<IntelligenceEvaluationRun, $this>
     */
    public function evaluationRun(): BelongsTo
    {
        return $this->belongsTo(IntelligenceEvaluationRun::class, 'evaluation_run_id');
    }

    /**
     * @return HasMany<IntelligenceEvaluationAssertionResult, $this>
     */
    public function assertionResults(): HasMany
    {
        return $this->hasMany(IntelligenceEvaluationAssertionResult::class, 'evaluation_case_run_id');
    }

    /**
     * @return HasMany<IntelligenceEvaluationHumanReview, $this>
     */
    public function humanReviews(): HasMany
    {
        return $this->hasMany(IntelligenceEvaluationHumanReview::class, 'evaluation_case_run_id');
    }

    /**
     * @return HasMany<IntelligenceEvaluationJudgeResult, $this>
     */
    public function judgeResults(): HasMany
    {
        return $this->hasMany(IntelligenceEvaluationJudgeResult::class, 'evaluation_case_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntelligenceEvaluationRunStatus::class,
            'safety_gate_status' => IntelligenceEvaluationGateStatus::class,
            'ablation_variant' => IntelligenceEvaluationAblationVariant::class,
            'retrieval_metrics' => 'array',
            'dimension_results' => 'array',
            'runtime_pins' => 'array',
            'mocked_output' => 'array',
        ];
    }
}
