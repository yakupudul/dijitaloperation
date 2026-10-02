<?php

namespace App\Support\Recommendations\Dto;

use App\Enums\RecommendationSourceKind;

/**
 * Safe, source-agnostic projection of the Finding or Opportunity behind a Recommendation.
 * Raw Evidence payloads are never exposed.
 */
final readonly class RecommendationSourceViewData
{
    /**
     * @param  list<int>  $goalIds
     * @param  list<int>  $offeringIds
     */
    public function __construct(
        public RecommendationSourceKind $kind,
        public int $id,
        public ?int $customerId,
        public ?int $brandId,
        public ?int $digitalAssetId,
        public string $title,
        public string $status,
        public ?string $category,
        public ?string $ruleId,
        public array $goalIds,
        public array $offeringIds,
        public ?string $market,
        public ?string $serviceContext,
        public int $supportingEvidenceCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'digital_asset_id' => $this->digitalAssetId,
            'title' => $this->title,
            'status' => $this->status,
            'category' => $this->category,
            'rule_id' => $this->ruleId,
            'goal_ids' => $this->goalIds,
            'offering_ids' => $this->offeringIds,
            'market' => $this->market,
            'service_context' => $this->serviceContext,
            'supporting_evidence_count' => $this->supportingEvidenceCount,
        ];
    }
}
