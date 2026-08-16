<?php

namespace App\Services\IntelligenceEvaluation;

use App\Models\IntelligenceEvaluationCaseRun;
use App\Models\IntelligenceEvaluationJudgeResult;
use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationCaseDefinition;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationPolicy;

/**
 * Optional advisory LLM Judge (Prompt 55).
 *
 * CI uses structured mocked advisory findings only — no paid live calls.
 * Never sole safety authority. Never overrides deterministic hard failures.
 * Chain-of-thought is never requested or stored.
 */
final class IntelligenceEvaluationAdvisoryJudge
{
    /**
     * @param  array<string, mixed>  $structuredOutput
     */
    public function evaluateAdvisory(
        IntelligenceEvaluationCaseRun $caseRun,
        IntelligenceEvaluationCaseDefinition $case,
        array $structuredOutput,
        bool $deterministicSafetyFailed,
    ): IntelligenceEvaluationJudgeResult {
        $findings = [
            'genericity' => ($structuredOutput['conclusions'] ?? []) === []
                ? 'needs_review'
                : 'pass',
            'semantic_support' => 'needs_review',
            'usefulness' => 'needs_review',
            'numeric_score' => null,
            'chain_of_thought' => null,
        ];

        // Even if judge would say "excellent", safety override is rejected.
        $attemptedOverride = $deterministicSafetyFailed;
        $overrideAccepted = false;

        return IntelligenceEvaluationJudgeResult::query()->create([
            'evaluation_case_run_id' => $caseRun->id,
            'judge_contract_version' => IntelligenceEvaluationPolicy::JUDGE_CONTRACT_VERSION,
            'judge_route_version' => 'eval_judge_mock@1',
            'judge_model' => 'mock-advisory-judge',
            'same_model_as_subject' => true,
            'is_advisory' => true,
            'attempted_safety_override' => $attemptedOverride,
            'safety_override_accepted' => $overrideAccepted,
            'structured_findings' => $findings,
        ]);
    }
}
