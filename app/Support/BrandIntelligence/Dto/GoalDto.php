<?php

namespace App\Support\BrandIntelligence\Dto;

/**
 * @phpstan-type OfferingIdList list<int>
 */
final class GoalDto
{
    /**
     * @param  OfferingIdList  $offeringIds
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $note,
        public readonly ?string $conversionType,
        public readonly string $status,
        public readonly string $applicabilityMode,
        public readonly array $offeringIds,
        public readonly int $sortOrder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'note' => $this->note,
            'conversion_type' => $this->conversionType,
            'status' => $this->status,
            'applicability_mode' => $this->applicabilityMode,
            'offering_ids' => $this->offeringIds,
            'sort_order' => $this->sortOrder,
        ];
    }
}
