<?php

namespace App\Services\IntelligenceEvaluation;

use App\Enums\IntelligenceEvaluationAblationVariant;
use App\Enums\IntelligenceMemoryLayer;
use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationCaseDefinition;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\Dto\SkillMemoryLayerRequirement;
use App\Support\IntelligenceRetrieval\SkillRetrievalContract;

/**
 * Builds eval-only retrieval contracts for ablation — never mutates production.
 */
final class IntelligenceEvaluationContractFactory
{
    public const string EVAL_AGENT = 'intelligence-evaluation-agent@1.0.0';

    public const string EVAL_SKILL = 'intelligence.evaluation-fixture@1.0.0';

    /**
     * @return array{
     *     agent: string,
     *     skill: string,
     *     skill_memory_contract_override: SkillMemoryContract,
     *     agent_permission_override: AgentMemoryPermission,
     *     retrieval_contract_override: SkillRetrievalContract,
     *     explicit_goal_ids?: list<int>
     * }
     */
    public function optionsFor(
        IntelligenceEvaluationCaseDefinition $case,
        array $fixtures,
    ): array {
        $variant = $case->ablationVariant ?? IntelligenceEvaluationAblationVariant::FullRetrieval;
        $layers = match ($variant) {
            IntelligenceEvaluationAblationVariant::EvidenceOnly => [],
            IntelligenceEvaluationAblationVariant::PlusBrandMemory => [IntelligenceMemoryLayer::Brand],
            IntelligenceEvaluationAblationVariant::PlusSector => [IntelligenceMemoryLayer::Sector],
            IntelligenceEvaluationAblationVariant::PlusSkillKnowledge => [IntelligenceMemoryLayer::Skill],
            IntelligenceEvaluationAblationVariant::FullRetrieval => [
                IntelligenceMemoryLayer::Brand,
                IntelligenceMemoryLayer::Sector,
                IntelligenceMemoryLayer::Skill,
            ],
        };

        // New brand cases still allow Sector+Skill under FullRetrieval.
        if (! $case->expectBrandHistory) {
            $layers = array_values(array_filter(
                $layers,
                static fn (IntelligenceMemoryLayer $l) => $l !== IntelligenceMemoryLayer::Brand
                    || $variant === IntelligenceEvaluationAblationVariant::PlusBrandMemory
            ));
            // For FullRetrieval on new brand: Brand layer requested but empty is OK —
            // keep Brand in contract so empty-layer assertion is meaningful when history expected false.
            if ($variant === IntelligenceEvaluationAblationVariant::FullRetrieval) {
                $layers = [
                    IntelligenceMemoryLayer::Brand,
                    IntelligenceMemoryLayer::Sector,
                    IntelligenceMemoryLayer::Skill,
                ];
            }
        }

        $requirements = [];
        foreach ($layers as $layer) {
            $requirements[] = new SkillMemoryLayerRequirement(
                layer: $layer,
                purpose: match ($layer) {
                    IntelligenceMemoryLayer::Brand => 'history',
                    IntelligenceMemoryLayer::Sector => 'cohort',
                    IntelligenceMemoryLayer::Skill => 'methodology',
                },
                maximumRetrievalCount: match ($layer) {
                    IntelligenceMemoryLayer::Brand => 5,
                    IntelligenceMemoryLayer::Sector => 3,
                    IntelligenceMemoryLayer::Skill => 2,
                },
                requiresPrivacyQualification: $layer === IntelligenceMemoryLayer::Sector,
            );
        }

        $memory = new SkillMemoryContract(self::EVAL_SKILL, $requirements);
        $retrieval = new SkillRetrievalContract(
            skillSignature: self::EVAL_SKILL,
            memoryContract: $memory,
            includeCurrentBrand: true,
            includeGoals: true,
            goalsRequired: $case->expectedGoalKeys !== [],
            maxGoals: 5,
        );

        $goalIds = [];
        foreach ($case->expectedGoalKeys as $key) {
            if (isset($fixtures['goals'][$key])) {
                $goalIds[] = (int) $fixtures['goals'][$key]->id;
            }
        }

        return [
            'agent' => self::EVAL_AGENT,
            'skill' => self::EVAL_SKILL,
            'skill_memory_contract_override' => $memory,
            'agent_permission_override' => new AgentMemoryPermission(self::EVAL_AGENT, [
                IntelligenceMemoryLayer::Brand,
                IntelligenceMemoryLayer::Sector,
                IntelligenceMemoryLayer::Skill,
            ]),
            'retrieval_contract_override' => $retrieval,
            'explicit_goal_ids' => $goalIds,
        ];
    }
}
