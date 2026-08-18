<?php

namespace App\Services\IntelligenceEvaluation;

use App\Models\IntelligenceEvaluationBaseline;
use App\Models\IntelligenceEvaluationRun;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationPolicy;

/**
 * Explicit versioned baselines — never implicit "last run".
 */
final class IntelligenceEvaluationBaselineService
{
    public function register(
        string $baselineKey,
        string $label,
        IntelligenceEvaluationRun $run,
    ): IntelligenceEvaluationBaseline {
        return IntelligenceEvaluationBaseline::query()->updateOrCreate(
            ['baseline_key' => $baselineKey],
            [
                'label' => $label,
                'evaluation_policy_version' => IntelligenceEvaluationPolicy::VERSION,
                'suite_key' => $run->suite_key,
                'suite_version' => $run->suite_version,
                'dataset_version' => $run->dataset_version,
                'agent_definition_signature' => $run->agent_definition_signature,
                'skill_definition_signature' => $run->skill_definition_signature,
                'ai_route_version' => $run->ai_route_version,
                'retrieval_policy_version' => $run->retrieval_policy_version,
                'baseline_run_id' => $run->id,
                'dimension_snapshot' => $run->dimension_summary,
                'is_explicit' => true,
            ]
        );
    }
}
