<?php

namespace App\Services\IntelligenceRetrieval;

use App\Enums\GoalStatus;
use App\Enums\IntelligenceRetrievalDecision;
use App\Enums\IntelligenceRetrievalReasonCode;
use App\Enums\IntelligenceSourceAuthority;
use App\Models\Brand;
use App\Models\BrandGoal;
use App\Support\IntelligenceRetrieval\Dto\RetrievalSectionDecision;
use App\Support\IntelligenceRetrieval\SkillRetrievalContract;

/**
 * Deterministic Goal retrieval using Prompt 37 BrandGoal identity — no keyword inference.
 */
final class RelevantGoalRetriever
{
    /**
     * @param  list<int>  $explicitGoalIds
     * @return array{goals: list<array<string, mixed>>, decision: RetrievalSectionDecision}
     */
    public function retrieve(
        Brand $brand,
        SkillRetrievalContract $contract,
        array $explicitGoalIds = [],
    ): array {
        if (! $contract->includeGoals) {
            return [
                'goals' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'goals',
                    decision: IntelligenceRetrievalDecision::NotRequested,
                    reasonCodes: [IntelligenceRetrievalReasonCode::SkillDoesNotRequest->value],
                    authority: IntelligenceSourceAuthority::CurrentCanonicalContext,
                ),
            ];
        }

        $query = BrandGoal::query()
            ->where('brand_id', $brand->id)
            ->where('status', GoalStatus::Active->value);

        if ($explicitGoalIds !== []) {
            $query->whereIn('id', $explicitGoalIds);
        } elseif (! $contract->allowBrandWideGoals) {
            return [
                'goals' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'goals',
                    decision: $contract->goalsRequired
                        ? IntelligenceRetrievalDecision::RequiredMissing
                        : IntelligenceRetrievalDecision::Unavailable,
                    reasonCodes: [IntelligenceRetrievalReasonCode::GoalNotAvailable->value],
                    authority: IntelligenceSourceAuthority::CurrentCanonicalContext,
                ),
            ];
        }

        $goals = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(max(1, $contract->maxGoals))
            ->get();

        if ($contract->goalsRequired && $goals->isEmpty()) {
            return [
                'goals' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'goals',
                    decision: IntelligenceRetrievalDecision::RequiredMissing,
                    reasonCodes: [IntelligenceRetrievalReasonCode::GoalNotAvailable->value],
                    authority: IntelligenceSourceAuthority::CurrentCanonicalContext,
                ),
            ];
        }

        // Multiple goals when Skill requires exactly one primary: do not pick first.
        if ($contract->goalsRequired && $contract->maxGoals === 1 && $goals->count() > 1 && $explicitGoalIds === []) {
            return [
                'goals' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'goals',
                    decision: IntelligenceRetrievalDecision::Blocked,
                    reasonCodes: [IntelligenceRetrievalReasonCode::GoalSelectionRequired->value],
                    candidateCount: $goals->count(),
                    authority: IntelligenceSourceAuthority::CurrentCanonicalContext,
                ),
            ];
        }

        $payload = $goals->map(static fn (BrandGoal $goal): array => [
            'id' => (int) $goal->id,
            'label' => $goal->label,
            'kind' => $goal->kind?->value,
            'status' => $goal->status?->value,
            'normalized_key' => $goal->normalized_key,
        ])->all();

        return [
            'goals' => $payload,
            'decision' => new RetrievalSectionDecision(
                section: 'goals',
                decision: $payload === []
                    ? IntelligenceRetrievalDecision::Unavailable
                    : IntelligenceRetrievalDecision::Included,
                reasonCodes: $payload === []
                    ? [IntelligenceRetrievalReasonCode::OptionalEmpty->value]
                    : [],
                candidateCount: count($payload),
                selectedCount: count($payload),
                authority: IntelligenceSourceAuthority::CurrentCanonicalContext,
            ),
        ];
    }
}
