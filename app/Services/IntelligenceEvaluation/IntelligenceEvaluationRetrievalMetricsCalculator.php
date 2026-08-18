<?php

namespace App\Services\IntelligenceEvaluation;

use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationCaseDefinition;
use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationRetrievalMetrics;
use App\Support\IntelligenceRetrieval\Dto\IntelligenceContextPack;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;

/**
 * Separate retrieval metrics — never a composite retrieval score.
 */
final class IntelligenceEvaluationRetrievalMetricsCalculator
{
    /**
     * @param  array<string, mixed>  $fixtures
     */
    public function calculate(
        IntelligenceEvaluationCaseDefinition $case,
        IntelligenceContextPack $pack,
        array $fixtures,
    ): IntelligenceEvaluationRetrievalMetrics {
        $selectedRefs = [];
        $relevantRefs = [];
        $requiredRefs = [];
        $optionalRefs = [];
        $privacyForbidden = [];

        if ($pack->currentBrandContext !== []) {
            $selectedRefs[] = 'current_brand';
            $relevantRefs[] = 'current_brand';
            $requiredRefs[] = 'current_brand';
        }

        if ($pack->evidencePack !== null) {
            $selectedRefs[] = 'evidence';
            if ($case->requiredEvidenceKeys !== []) {
                $relevantRefs[] = 'evidence';
                $requiredRefs[] = 'evidence';
            }
        }

        foreach ($pack->relevantGoals as $goal) {
            $key = 'goal:'.(string) ($goal['normalized_key'] ?? $goal['id'] ?? '');
            $selectedRefs[] = $key;
            if (in_array((string) ($goal['normalized_key'] ?? ''), $case->expectedGoalKeys, true)) {
                $relevantRefs[] = $key;
                $requiredRefs[] = $key;
            }
        }

        foreach ($pack->memoryContextPack->brandExperiences as $item) {
            $selectedRefs[] = $item->opaqueRef;
            if ($case->expectBrandHistory) {
                $relevantRefs[] = $item->opaqueRef;
                $requiredRefs[] = $item->opaqueRef;
            }
        }

        $sectorExpected = $case->expectedSectorKeys;
        foreach ($pack->memoryContextPack->sectorPatterns as $item) {
            $ref = 'sector_artifact:'.$item->artifact->artifactStableKey;
            $selectedRefs[] = $ref;
            $evalKey = (string) ($item->artifact->aggregateResult['eval_key'] ?? '');
            if ($evalKey !== '' && in_array($evalKey, $sectorExpected, true)) {
                $relevantRefs[] = $ref;
                $requiredRefs[] = $ref;
            } elseif ($evalKey === 'dental_privacy_blocked') {
                $privacyForbidden[] = $ref;
            } elseif ($evalKey !== '' && $evalKey !== 'dental_paid_search_relevant') {
                $optionalRefs[] = $ref;
            } elseif ($sectorExpected !== [] && $evalKey === 'dental_paid_search_relevant') {
                $relevantRefs[] = $ref;
                $requiredRefs[] = $ref;
            }
        }

        foreach ($pack->memoryContextPack->skillKnowledge as $item) {
            $selectedRefs[] = $item->opaqueRef;
            $optionalRefs[] = $item->opaqueRef;
        }

        // Expected required set size from case (even if not selected).
        $requiredTotal = 0;
        $requiredTotal += 1; // current brand always required for subject cases
        if ($case->requiredEvidenceKeys !== []) {
            $requiredTotal += 1;
        }
        $requiredTotal += count($case->expectedGoalKeys);
        if ($case->expectBrandHistory) {
            $requiredTotal += max(1, count($fixtures['experiences'] ?? []));
        }
        $requiredTotal += count($case->expectedSectorKeys);

        $selectedUnique = array_values(array_unique($selectedRefs));
        $relevantUnique = array_values(array_unique($relevantRefs));
        $requiredSelected = array_values(array_unique(array_intersect($requiredRefs, $selectedUnique)));
        $irrelevant = array_values(array_diff($selectedUnique, $relevantUnique));

        $serialized = (string) json_encode($pack->toPromptSections());
        $bytes = strlen($serialized);
        $silent = $bytes > IntelligenceRetrievalPolicy::HARD_MAX_MEMORY_SERIALIZED_BYTES
            && ($pack->retrievalMetadata['silent_truncation'] ?? false) === true;

        return new IntelligenceEvaluationRetrievalMetrics(
            selectedCount: count($selectedUnique),
            requiredSelectedCount: count($requiredSelected),
            requiredTotalCount: max($requiredTotal, count($requiredSelected)),
            relevantSelectedCount: count($relevantUnique),
            relevantTotalCount: max(count($relevantUnique), count($case->expectedSectorKeys) + count($case->expectedGoalKeys) + ($case->requiredEvidenceKeys !== [] ? 1 : 0) + 1),
            irrelevantOverfetchCount: count($irrelevant),
            privacyOverfetchCount: count(array_unique($privacyForbidden)),
            optionalSelectedCount: count(array_unique($optionalRefs)),
            optionalTotalCount: count(array_unique($optionalRefs)),
            contextSerializedBytes: $bytes,
            silentTruncationDetected: $silent,
        );
    }
}
