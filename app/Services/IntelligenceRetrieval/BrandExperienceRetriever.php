<?php

namespace App\Services\IntelligenceRetrieval;

use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Enums\IntelligenceMatchReason;
use App\Enums\IntelligenceMemoryLayer;
use App\Enums\IntelligenceRetrievalDecision;
use App\Enums\IntelligenceRetrievalReasonCode;
use App\Enums\IntelligenceSourceAuthority;
use App\Models\BrandExperience;
use App\Support\IntelligenceMemory\Dto\BrandMemoryScope;
use App\Support\IntelligenceRetrieval\Dto\BrandExperienceContextItem;
use App\Support\IntelligenceRetrieval\Dto\RetrievalSectionDecision;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;
use App\Support\IntelligenceRetrieval\SkillRetrievalContract;
use Illuminate\Support\Str;

/**
 * Same-Brand structured Brand Experience retrieval — no text similarity / embeddings.
 */
final class BrandExperienceRetriever
{
    /**
     * @param  array{goal_ids?: list<int>, market_code?: string|null, channel?: string|null}  $filters
     * @return array{
     *     items: list<BrandExperienceContextItem>,
     *     decision: RetrievalSectionDecision
     * }
     */
    public function retrieve(
        BrandMemoryScope $scope,
        SkillRetrievalContract $contract,
        array $filters = [],
        int $maxItems = 5,
    ): array {
        $requirement = $contract->requirementFor(IntelligenceMemoryLayer::Brand);
        if ($requirement === null) {
            return [
                'items' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'brand_experience',
                    decision: IntelligenceRetrievalDecision::NotRequested,
                    reasonCodes: [IntelligenceRetrievalReasonCode::SkillDoesNotRequest->value],
                    authority: IntelligenceSourceAuthority::HistoricalBrandExperience,
                ),
            ];
        }

        $limit = min(
            $maxItems > 0 ? $maxItems : IntelligenceRetrievalPolicy::HARD_MAX_BRAND_EXPERIENCES,
            $requirement->maximumRetrievalCount > 0
                ? $requirement->maximumRetrievalCount
                : IntelligenceRetrievalPolicy::HARD_MAX_BRAND_EXPERIENCES,
            IntelligenceRetrievalPolicy::HARD_MAX_BRAND_EXPERIENCES,
        );

        $experiences = BrandExperience::query()
            ->with(['currentRevision.goals', 'currentRevision.offerings'])
            ->where('customer_id', $scope->customerId)
            ->where('brand_id', $scope->brandId)
            ->where('status', BrandExperienceStatus::Confirmed->value)
            ->get();

        $candidates = [];
        foreach ($experiences as $experience) {
            $revision = $experience->currentRevision;
            if ($revision === null) {
                continue;
            }

            $quality = $revision->support_status->value;
            if (! in_array($quality, $contract->allowedExperienceQualityStates, true)) {
                continue;
            }

            if ($revision->support_status === BrandExperienceSupportStatus::Insufficient) {
                continue;
            }

            $matchReasons = [IntelligenceMatchReason::ConfirmedEligible->value];
            $priority = [];

            $goalIds = $filters['goal_ids'] ?? [];
            if ($goalIds !== [] && in_array('goal', $contract->experienceMatchDimensions, true)) {
                $experienceGoalIds = $revision->goals->pluck('brand_goal_id')->filter()->map(fn ($id) => (int) $id)->all();
                $overlap = array_intersect($goalIds, $experienceGoalIds);
                if ($overlap !== []) {
                    $matchReasons[] = IntelligenceMatchReason::ExactGoalMatch->value;
                    $priority['exact_goal'] = 1;
                } else {
                    $priority['exact_goal'] = 0;
                }
            } else {
                $priority['exact_goal'] = 0;
            }

            $market = $filters['market_code'] ?? null;
            if (is_string($market) && $market !== '' && in_array('market', $contract->experienceMatchDimensions, true)) {
                if ($revision->market_code === $market) {
                    $matchReasons[] = IntelligenceMatchReason::ExactMarketMatch->value;
                    $priority['exact_market'] = 1;
                } else {
                    $priority['exact_market'] = 0;
                }
            } else {
                $priority['exact_market'] = 0;
            }

            $channel = $filters['channel'] ?? null;
            if (is_string($channel) && $channel !== '' && in_array('channel', $contract->experienceMatchDimensions, true)) {
                if ($revision->channel?->value === $channel) {
                    $matchReasons[] = IntelligenceMatchReason::ExactChannelMatch->value;
                    $priority['exact_channel'] = 1;
                } else {
                    $priority['exact_channel'] = 0;
                }
            } else {
                $priority['exact_channel'] = 0;
            }

            $priority['exact_offering'] = 0;
            $priority['exact_action_kind'] = 1;
            $priority['quality_class'] = $revision->support_status === BrandExperienceSupportStatus::Sufficient ? 2 : 1;
            $priority['recency'] = $revision->outcome_observed_at?->getTimestamp() ?? 0;
            $priority['stable_id'] = (int) $experience->id;

            $candidates[] = [
                'priority' => $priority,
                'item' => new BrandExperienceContextItem(
                    opaqueRef: 'brand_experience:'.$experience->id,
                    experienceRevisionId: (int) $revision->id,
                    revisionNumber: (int) $revision->revision_number,
                    marketCode: $revision->market_code,
                    channel: $revision->channel?->value,
                    actionKind: $revision->action_kind->value,
                    outcomeClarity: $revision->outcome_clarity->value,
                    supportStatus: $revision->support_status->value,
                    causalityStatus: $revision->causality_status->value,
                    actionOccurredAt: $revision->action_occurred_at?->toIso8601String(),
                    outcomeObservedAt: $revision->outcome_observed_at?->toIso8601String(),
                    boundedSituationSummary: $this->boundText($revision->situation_summary),
                    boundedActionSummary: $this->boundText($revision->action_summary),
                    boundedOutcomeSummary: $this->boundText($revision->outcome_summary),
                    matchReasons: array_values(array_unique($matchReasons)),
                    limitations: [
                        'causality_not_established',
                        'historical_context_only',
                        'does_not_override_current_evidence',
                    ],
                ),
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            foreach (IntelligenceRetrievalPolicy::DEFAULT_EXPERIENCE_ORDER as $key) {
                $av = $a['priority'][$key] ?? 0;
                $bv = $b['priority'][$key] ?? 0;
                if ($av === $bv) {
                    continue;
                }
                // Higher match / quality / recency first; stable_id ascending for tie-break
                if ($key === 'stable_id') {
                    return $av <=> $bv;
                }

                return $bv <=> $av;
            }

            return 0;
        });

        $candidateCount = count($candidates);
        $selected = array_slice($candidates, 0, $limit);
        $items = array_map(static fn (array $row): BrandExperienceContextItem => $row['item'], $selected);
        $omitted = max(0, $candidateCount - count($items));

        if ($items === []) {
            $decision = $requirement->required
                ? IntelligenceRetrievalDecision::RequiredMissing
                : IntelligenceRetrievalDecision::Unavailable;

            return [
                'items' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'brand_experience',
                    decision: $decision,
                    reasonCodes: [
                        $requirement->required
                            ? IntelligenceRetrievalReasonCode::NoRelevantBrandExperience->value
                            : IntelligenceRetrievalReasonCode::OptionalEmpty->value,
                    ],
                    candidateCount: $candidateCount,
                    selectedCount: 0,
                    omittedCount: 0,
                    authority: IntelligenceSourceAuthority::HistoricalBrandExperience,
                ),
            ];
        }

        return [
            'items' => $items,
            'decision' => new RetrievalSectionDecision(
                section: 'brand_experience',
                decision: $omitted > 0
                    ? IntelligenceRetrievalDecision::SelectedWithLimit
                    : IntelligenceRetrievalDecision::Included,
                reasonCodes: $omitted > 0
                    ? [IntelligenceRetrievalReasonCode::ContextBudgetExceeded->value]
                    : [],
                matchReasons: array_values(array_unique(array_merge(
                    ...array_map(static fn (BrandExperienceContextItem $i): array => $i->matchReasons, $items)
                ))),
                candidateCount: $candidateCount,
                selectedCount: count($items),
                omittedCount: $omitted,
                authority: IntelligenceSourceAuthority::HistoricalBrandExperience,
                safeMetadata: [
                    'selection_rule' => 'lexicographic_'.IntelligenceRetrievalPolicy::VERSION,
                ],
            ),
        ];
    }

    private function boundText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return Str::limit(trim($text), 400, '…');
    }
}
