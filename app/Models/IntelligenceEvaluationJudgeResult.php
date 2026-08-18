<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Advisory LLM Judge result (Prompt 55).
 *
 * Never sole safety authority. Never overrides deterministic hard failures.
 * Chain-of-thought is not stored.
 */
class IntelligenceEvaluationJudgeResult extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'evaluation_case_run_id',
        'judge_contract_version',
        'judge_route_version',
        'judge_model',
        'same_model_as_subject',
        'is_advisory',
        'attempted_safety_override',
        'safety_override_accepted',
        'structured_findings',
    ];

    /**
     * @return BelongsTo<IntelligenceEvaluationCaseRun, $this>
     */
    public function caseRun(): BelongsTo
    {
        return $this->belongsTo(IntelligenceEvaluationCaseRun::class, 'evaluation_case_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'same_model_as_subject' => 'boolean',
            'is_advisory' => 'boolean',
            'attempted_safety_override' => 'boolean',
            'safety_override_accepted' => 'boolean',
            'structured_findings' => 'array',
        ];
    }
}
