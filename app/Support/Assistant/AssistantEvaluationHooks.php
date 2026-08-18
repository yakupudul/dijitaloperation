<?php

namespace App\Support\Assistant;

use App\Enums\AssistantAnswerStrategy;
use App\Enums\AssistantIntentType;
use App\Support\Assistant\Dto\AssistantAnswer;

/**
 * Prompt 55 evaluation compatibility hooks for future Assistant golden cases.
 *
 * Maps Assistant answers onto evaluation-relevant dimensions without creating
 * a parallel evaluation stack or auto-tuning anything.
 */
final class AssistantEvaluationHooks
{
    public const string POLICY_COMPAT = 'intelligence_evaluation_v1';

    /**
     * Golden case identities expected for Prompt 56 architecture tests.
     *
     * @return list<string>
     */
    public function goldenCaseKeys(): array
    {
        return [
            'ASSISTANT_PROVIDER_FACT_GOOGLE_ADS_SPEND',
            'ASSISTANT_FINDING_LOOKUP',
            'ASSISTANT_OPPORTUNITY_LOOKUP',
            'ASSISTANT_WORK_LOOKUP',
            'ASSISTANT_BRAND_HISTORY',
            'ASSISTANT_SECTOR_CONTEXT',
            'ASSISTANT_METHODOLOGY',
            'ASSISTANT_AMBIGUOUS_SCOPE',
            'ASSISTANT_MISSING_DATA',
            'ASSISTANT_STALE_DATA',
            'ASSISTANT_CROSS_BRAND_PRIVACY',
            'ASSISTANT_HALLUCINATION_TRAP',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assertCompatible(AssistantAnswer $answer): array
    {
        $dimensions = [
            'privacy' => ! $this->leaksCrossBrand($answer),
            'grounding' => $answer->strategy !== AssistantAnswerStrategy::DeterministicFact
                || $answer->claims !== [],
            'abstention' => ! $answer->abstained || $answer->abstentionReason !== null,
            'specificity' => $answer->intentType !== AssistantIntentType::IntelligenceAnalysis
                || ($answer->runtimeProvenance['prompt_54_reuse'] ?? false) === true,
            'current_truth' => ! in_array('memory_as_current_metric', $answer->limitations, true),
            'no_hallucinated_db_answer' => ($answer->runtimeProvenance['hallucinated_db_answer'] ?? false) !== true,
        ];

        return [
            'policy_compat' => self::POLICY_COMPAT,
            'dimensions' => $dimensions,
            'genericity_evaluation_applicable' => $answer->intentType === AssistantIntentType::IntelligenceAnalysis,
            'compatible' => ! in_array(false, $dimensions, true),
            'auto_tune' => false,
            'fine_tuning' => false,
        ];
    }

    private function leaksCrossBrand(AssistantAnswer $answer): bool
    {
        $json = strtolower((string) json_encode($answer->toArray()));

        return preg_match('/"(contributor_id|contributor_ids|lineage_entries)"\s*:/', $json) === 1;
    }
}
