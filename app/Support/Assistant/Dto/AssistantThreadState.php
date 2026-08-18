<?php

namespace App\Support\Assistant\Dto;

/**
 * Structured multi-turn carry-forward state — not Brand Memory / Evidence / authority.
 */
final class AssistantThreadState
{
    /**
     * @param  array<string, mixed>  $references
     */
    public function __construct(
        public readonly ?int $customerId = null,
        public readonly ?int $brandId = null,
        public readonly ?int $digitalAssetId = null,
        public readonly ?string $metricId = null,
        public readonly ?string $periodToken = null,
        public readonly ?string $lastFindingRef = null,
        public readonly ?string $lastOpportunityRef = null,
        public readonly array $references = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'digital_asset_id' => $this->digitalAssetId,
            'metric_id' => $this->metricId,
            'period_token' => $this->periodToken,
            'last_finding_ref' => $this->lastFindingRef,
            'last_opportunity_ref' => $this->lastOpportunityRef,
            'references' => $this->references,
            'is_brand_memory' => false,
            'is_evidence' => false,
            'is_authorization' => false,
            'auto_long_term_learning' => false,
        ];
    }
}
