<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\IntelligenceMemoryLayer;
use App\Enums\MemoryQualityState;
use App\Enums\MemorySourceKind;
use App\Enums\MemoryValidityState;

/**
 * Provenance contract for future memory artifacts (Prompt 51).
 *
 * References source identity — does not duplicate source payloads.
 *
 * @phpstan-type ProvenanceArray array{
 *     layer: string,
 *     source_kind: string,
 *     source_identity: string|null,
 *     source_revision: string|null,
 *     created_by: string|null,
 *     derived_by: string|null,
 *     policy_version: string|null,
 *     methodology_version: string|null,
 *     effective_at: string|null,
 *     observed_period: string|null,
 *     created_at: string|null,
 *     quality_state: string,
 *     validity_state: string,
 *     superseded_by: string|null,
 *     supersedes: string|null,
 *     review_state: string|null,
 *     consumer_citation: string|null,
 *     contributor_ids_visible_to_consumer: false
 * }
 */
final class MemoryProvenance
{
    public function __construct(
        public readonly IntelligenceMemoryLayer $layer,
        public readonly MemorySourceKind $sourceKind,
        public readonly ?string $sourceIdentity = null,
        public readonly ?string $sourceRevision = null,
        public readonly ?string $createdBy = null,
        public readonly ?string $derivedBy = null,
        public readonly ?string $policyVersion = null,
        public readonly ?string $methodologyVersion = null,
        public readonly ?string $effectiveAt = null,
        public readonly ?string $observedPeriod = null,
        public readonly ?string $createdAt = null,
        public readonly MemoryQualityState $qualityState = MemoryQualityState::Unverified,
        public readonly MemoryValidityState $validityState = MemoryValidityState::NeedsReview,
        public readonly ?string $supersededBy = null,
        public readonly ?string $supersedes = null,
        public readonly ?string $reviewState = null,
        public readonly ?string $consumerCitation = null,
    ) {}

    /**
     * Sector consumer provenance must never expose contributor Brand/Customer IDs.
     *
     * @return ProvenanceArray
     */
    public function toConsumerSafeArray(): array
    {
        return [
            'layer' => $this->layer->value,
            'source_kind' => $this->sourceKind->value,
            'source_identity' => $this->sourceIdentity,
            'source_revision' => $this->sourceRevision,
            'created_by' => $this->createdBy,
            'derived_by' => $this->derivedBy,
            'policy_version' => $this->policyVersion,
            'methodology_version' => $this->methodologyVersion,
            'effective_at' => $this->effectiveAt,
            'observed_period' => $this->observedPeriod,
            'created_at' => $this->createdAt,
            'quality_state' => $this->qualityState->value,
            'validity_state' => $this->validityState->value,
            'superseded_by' => $this->supersededBy,
            'supersedes' => $this->supersedes,
            'review_state' => $this->reviewState,
            'consumer_citation' => $this->consumerCitation,
            'contributor_ids_visible_to_consumer' => false,
        ];
    }
}
