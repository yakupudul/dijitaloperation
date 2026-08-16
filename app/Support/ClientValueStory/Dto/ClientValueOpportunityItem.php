<?php

namespace App\Support\ClientValueStory\Dto;

final class ClientValueOpportunityItem
{
    public function __construct(
        public readonly int $opportunityId,
        public readonly string $title,
        public readonly string $status,
        public readonly ?string $qualitativePriority,
        public readonly ?int $digitalAssetId,
        public readonly ?string $goalLabel,
        public readonly ?string $serviceLabel,
        public readonly ?string $firstDetectedAt,
        public readonly ?string $lastDetectedAt,
        public readonly ?string $closedAt,
        public readonly bool $isPotential = true,
        public readonly bool $realizedValue = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'opportunity_id' => $this->opportunityId,
            'title' => $this->title,
            'status' => $this->status,
            'qualitative_priority' => $this->qualitativePriority,
            'digital_asset_id' => $this->digitalAssetId,
            'goal_label' => $this->goalLabel,
            'service_label' => $this->serviceLabel,
            'first_detected_at' => $this->firstDetectedAt,
            'last_detected_at' => $this->lastDetectedAt,
            'closed_at' => $this->closedAt,
            'is_potential' => $this->isPotential,
            'realized_value' => $this->realizedValue,
            'section' => 'potential',
            'business_impact_claimed' => false,
            'causality_claimed' => false,
            'magic_value_score' => null,
        ];
    }
}
