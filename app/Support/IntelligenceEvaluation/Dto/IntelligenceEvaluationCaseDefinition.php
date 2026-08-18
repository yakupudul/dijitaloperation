<?php

namespace App\Support\IntelligenceEvaluation\Dto;

use App\Enums\IntelligenceEvaluationAblationVariant;
use App\Enums\IntelligenceEvaluationAssertionType;

/**
 * Versioned Evaluation Case definition (Prompt 55).
 *
 * Stable case_key + case_version. Expectations live in code/catalog —
 * silent golden rewrites to make a model pass are forbidden.
 */
final class IntelligenceEvaluationCaseDefinition
{
    /**
     * @param  list<string>  $suiteKeys
     * @param  list<string>  $requiredEvidenceKeys
     * @param  list<string>  $expectedGoalKeys
     * @param  list<string>  $expectedSectorKeys
     * @param  list<string>  $forbiddenCanaries
     * @param  list<string>  $forbiddenClaimPatterns
     * @param  list<string>  $requiredConclusionTypes
     * @param  list<string>  $forbiddenConclusionTypes
     * @param  list<IntelligenceEvaluationAssertionType>  $assertions
     * @param  array<string, mixed>  $fixtureHints
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $caseKey,
        public readonly string $caseVersion,
        public readonly string $datasetKey,
        public readonly string $datasetVersion,
        public readonly string $title,
        public readonly array $suiteKeys,
        public readonly string $subjectBrandKey,
        public readonly bool $expectBrandHistory,
        public readonly bool $expectAbstention,
        public readonly ?string $expectedAbstentionReason,
        public readonly array $requiredEvidenceKeys,
        public readonly array $expectedGoalKeys,
        public readonly array $expectedSectorKeys,
        public readonly array $forbiddenCanaries,
        public readonly array $forbiddenClaimPatterns,
        public readonly array $requiredConclusionTypes,
        public readonly array $forbiddenConclusionTypes,
        public readonly array $assertions,
        public readonly ?string $counterfactualPairKey,
        public readonly ?IntelligenceEvaluationAblationVariant $ablationVariant,
        public readonly array $fixtureHints,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'case_key' => $this->caseKey,
            'case_version' => $this->caseVersion,
            'dataset_key' => $this->datasetKey,
            'dataset_version' => $this->datasetVersion,
            'title' => $this->title,
            'suite_keys' => $this->suiteKeys,
            'subject_brand_key' => $this->subjectBrandKey,
            'expect_brand_history' => $this->expectBrandHistory,
            'expect_abstention' => $this->expectAbstention,
            'expected_abstention_reason' => $this->expectedAbstentionReason,
            'required_evidence_keys' => $this->requiredEvidenceKeys,
            'expected_goal_keys' => $this->expectedGoalKeys,
            'expected_sector_keys' => $this->expectedSectorKeys,
            'forbidden_canaries' => $this->forbiddenCanaries,
            'forbidden_claim_patterns' => $this->forbiddenClaimPatterns,
            'required_conclusion_types' => $this->requiredConclusionTypes,
            'forbidden_conclusion_types' => $this->forbiddenConclusionTypes,
            'assertions' => array_map(
                static fn (IntelligenceEvaluationAssertionType $t) => $t->value,
                $this->assertions
            ),
            'counterfactual_pair_key' => $this->counterfactualPairKey,
            'ablation_variant' => $this->ablationVariant?->value,
            'fixture_hints' => $this->fixtureHints,
            'metadata' => $this->metadata,
        ];
    }
}
