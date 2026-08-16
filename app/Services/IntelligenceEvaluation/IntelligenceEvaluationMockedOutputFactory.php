<?php

namespace App\Services\IntelligenceEvaluation;

use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationCaseDefinition;
use App\Support\IntelligenceRetrieval\Dto\IntelligenceContextPack;

/**
 * Deterministic mocked structured Agent output for CI (Prompt 55).
 *
 * Never claims live-model usefulness. Never calls business providers.
 */
final class IntelligenceEvaluationMockedOutputFactory
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        IntelligenceEvaluationCaseDefinition $case,
        IntelligenceContextPack $pack,
    ): array {
        if ($case->expectAbstention || $pack->evidencePack === null) {
            return [
                'abstained' => true,
                'abstention_reason' => $case->expectedAbstentionReason ?? 'required_evidence_missing',
                'conclusions' => [],
                'evidence_refs' => [],
                'memory_refs' => [],
                'limitations' => ['insufficient_evidence'],
            ];
        }

        $evidenceRefs = $pack->evidencePack->evidenceIds();
        $memoryRefs = [];
        foreach ($pack->memoryContextPack->brandExperiences as $item) {
            $memoryRefs[] = $item->opaqueRef;
        }
        foreach ($pack->memoryContextPack->sectorPatterns as $item) {
            $memoryRefs[] = 'sector_artifact:'.$item->artifact->artifactStableKey;
        }

        $conclusions = [];
        foreach ($case->requiredConclusionTypes as $type) {
            $conclusions[] = [
                'type' => $type,
                'claim' => 'Context-specific evaluation conclusion: '.$type,
                'evidence_refs' => $evidenceRefs,
                'memory_refs' => [],
                'limitations' => ['observational_only'],
            ];
        }

        if ($conclusions === []) {
            $conclusions[] = [
                'type' => 'grounded_observation',
                'claim' => 'Analysis uses current Brand Evidence and Goal context.',
                'evidence_refs' => $evidenceRefs,
                'memory_refs' => $case->expectBrandHistory ? $memoryRefs : [],
                'limitations' => ['partial_evidence_possible'],
            ];
        }

        // Current-truth case: explicitly acknowledge current market from goals/context.
        if ($case->caseKey === 'CURRENT_TRUTH_MARKET_CONFLICT') {
            $conclusions[] = [
                'type' => 'current_goal_authority',
                'claim' => 'Current Goal remains Netherlands; historical Germany does not override.',
                'evidence_refs' => $evidenceRefs,
                'memory_refs' => $memoryRefs,
                'limitations' => ['historical_context_non_authoritative'],
            ];
        }

        return [
            'abstained' => false,
            'abstention_reason' => null,
            'conclusions' => $conclusions,
            'evidence_refs' => $evidenceRefs,
            'memory_refs' => $case->expectBrandHistory || $memoryRefs !== [] ? $memoryRefs : [],
            'limitations' => ['no_causal_certainty'],
            'provider_calls' => 0,
            'domain_writes' => 0,
        ];
    }
}
