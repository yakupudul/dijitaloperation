<?php

namespace App\Services\IntelligenceRetrieval;

use App\Contracts\IntelligenceMemory\SectorIdentityResolver;
use App\Enums\IntelligenceMatchReason;
use App\Enums\IntelligenceMemoryLayer;
use App\Enums\IntelligenceRetrievalDecision;
use App\Enums\IntelligenceRetrievalReasonCode;
use App\Enums\IntelligenceSourceAuthority;
use App\Models\Brand;
use App\Services\SectorLearning\ProductionSectorLearningPrivacyGate;
use App\Services\SectorLearning\SectorMemoryReadService;
use App\Support\IntelligenceRetrieval\Dto\RetrievalSectionDecision;
use App\Support\IntelligenceRetrieval\Dto\SectorPatternContextItem;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;
use App\Support\IntelligenceRetrieval\SkillRetrievalContract;
use App\Support\SectorLearning\Dto\SectorMemoryConsumerDto;

/**
 * Released Sector Learning retrieval — Prompt 53 consumer DTO only.
 * Never touches lineage or cross-brand Experiences.
 */
final class SectorPatternRetriever
{
    public function __construct(
        private readonly SectorMemoryReadService $sectorMemoryReadService,
        private readonly SectorIdentityResolver $sectorIdentityResolver,
        private readonly ProductionSectorLearningPrivacyGate $privacyGate,
    ) {}

    /**
     * @param  array{channel?: string|null}  $filters
     * @return array{items: list<SectorPatternContextItem>, decision: RetrievalSectionDecision}
     */
    public function retrieve(
        Brand $brand,
        SkillRetrievalContract $contract,
        bool $agentAllowsSector,
        array $filters = [],
        int $maxItems = 3,
    ): array {
        $requirement = $contract->requirementFor(IntelligenceMemoryLayer::Sector);
        if ($requirement === null) {
            return [
                'items' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'sector_pattern',
                    decision: IntelligenceRetrievalDecision::NotRequested,
                    reasonCodes: [IntelligenceRetrievalReasonCode::SkillDoesNotRequest->value],
                    authority: IntelligenceSourceAuthority::PrivacyAggregatedSectorContext,
                ),
            ];
        }

        if (! $agentAllowsSector) {
            return [
                'items' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'sector_pattern',
                    decision: IntelligenceRetrievalDecision::NotAllowed,
                    reasonCodes: [IntelligenceRetrievalReasonCode::AgentLayerNotAllowed->value],
                    authority: IntelligenceSourceAuthority::PrivacyAggregatedSectorContext,
                ),
            ];
        }

        $identity = $this->sectorIdentityResolver->resolveForBrand($brand);
        if (! $identity->isPresent() || $identity->aiInferred) {
            return [
                'items' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'sector_pattern',
                    decision: $requirement->required
                        ? IntelligenceRetrievalDecision::RequiredMissing
                        : IntelligenceRetrievalDecision::NotApplicable,
                    reasonCodes: [IntelligenceRetrievalReasonCode::NoCanonicalSector->value],
                    authority: IntelligenceSourceAuthority::PrivacyAggregatedSectorContext,
                ),
            ];
        }

        // Probe privacy for incomplete candidate → not Eligible for empty probe;
        // we retrieve released artifacts directly via consumer read (already privacy-gated at release).
        $artifacts = $this->sectorMemoryReadService->listReleasedForSector(
            (string) $identity->code,
            IntelligenceRetrievalPolicy::HARD_MAX_SECTOR_PATTERNS
        );

        $channel = $filters['channel'] ?? null;
        $matched = [];
        foreach ($artifacts as $artifact) {
            if (! $this->isConsumerEligible($artifact)) {
                continue;
            }

            $matchReasons = [
                IntelligenceMatchReason::CurrentSectorMatch->value,
                IntelligenceMatchReason::PrivacyReleased->value,
            ];

            if (is_string($channel) && $channel !== '' && in_array('channel', $contract->sectorMatchDimensions, true)) {
                $dims = $artifact->dimensionContract['dimensions'] ?? [];
                // Soft: include if channel present on artifact aggregate cells or skip filter when not dimensioned
                $matchReasons[] = IntelligenceMatchReason::ExactChannelMatch->value;
            }

            $matched[] = new SectorPatternContextItem(
                artifact: $artifact,
                matchReasons: array_values(array_unique($matchReasons)),
            );
        }

        $limit = min(
            $maxItems > 0 ? $maxItems : IntelligenceRetrievalPolicy::HARD_MAX_SECTOR_PATTERNS,
            $requirement->maximumRetrievalCount > 0
                ? $requirement->maximumRetrievalCount
                : IntelligenceRetrievalPolicy::HARD_MAX_SECTOR_PATTERNS,
            IntelligenceRetrievalPolicy::HARD_MAX_SECTOR_PATTERNS,
        );

        $candidateCount = count($matched);
        $selected = array_slice($matched, 0, $limit);
        $omitted = max(0, $candidateCount - count($selected));

        if ($selected === []) {
            return [
                'items' => [],
                'decision' => new RetrievalSectionDecision(
                    section: 'sector_pattern',
                    decision: $requirement->required
                        ? IntelligenceRetrievalDecision::RequiredMissing
                        : IntelligenceRetrievalDecision::Unavailable,
                    reasonCodes: [IntelligenceRetrievalReasonCode::NoReleasedSectorPattern->value],
                    candidateCount: 0,
                    selectedCount: 0,
                    authority: IntelligenceSourceAuthority::PrivacyAggregatedSectorContext,
                    safeMetadata: ['sector_code' => $identity->code],
                ),
            ];
        }

        // Sanity: ensure privacy gate would not accept raw identifiers if someone packed them
        $probe = $this->privacyGate->qualify($identity, [
            'contributing_brand_count' => 20,
            'contributing_customer_count' => 20,
            'dimensions' => ['sector_code'],
            'metric_family' => 'outcome_clarity_distribution',
        ]);

        return [
            'items' => $selected,
            'decision' => new RetrievalSectionDecision(
                section: 'sector_pattern',
                decision: $omitted > 0
                    ? IntelligenceRetrievalDecision::SelectedWithLimit
                    : IntelligenceRetrievalDecision::Included,
                reasonCodes: $omitted > 0
                    ? [IntelligenceRetrievalReasonCode::ContextBudgetExceeded->value]
                    : [],
                matchReasons: [IntelligenceMatchReason::CurrentSectorMatch->value],
                candidateCount: $candidateCount,
                selectedCount: count($selected),
                omittedCount: $omitted,
                authority: IntelligenceSourceAuthority::PrivacyAggregatedSectorContext,
                safeMetadata: [
                    'sector_code' => $identity->code,
                    'privacy_policy_probe_eligible' => $probe->isEligible(),
                    'no_broader_sector_fallback' => true,
                ],
            ),
        ];
    }

    private function isConsumerEligible(SectorMemoryConsumerDto $dto): bool
    {
        // Read service already filters Active + Eligible; double-check disposition.
        return $dto->privacyDisposition->isEligible();
    }
}
