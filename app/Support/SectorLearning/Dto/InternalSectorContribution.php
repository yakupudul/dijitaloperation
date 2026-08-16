<?php

namespace App\Support\SectorLearning\Dto;

/**
 * Internal contribution unit with restricted lineage pointers.
 * Never serialize this object into Sector Memory consumer DTOs.
 */
final class InternalSectorContribution
{
    public function __construct(
        public readonly SafeSectorContributionProjection $projection,
        public readonly int $brandExperienceId,
        public readonly int $brandExperienceRevisionId,
        public readonly int $brandId,
        public readonly int $customerId,
        public readonly float $effectiveWeight = 1.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toRestrictedAuditArray(): array
    {
        return [
            'projection' => $this->projection->toConsumerSafeArray(),
            'brand_experience_id' => $this->brandExperienceId,
            'brand_experience_revision_id' => $this->brandExperienceRevisionId,
            'brand_id' => $this->brandId,
            'customer_id' => $this->customerId,
            'effective_weight' => $this->effectiveWeight,
        ];
    }
}
