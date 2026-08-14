<?php

namespace App\Support\Evidence\Dto;

use App\Models\Evidence;

final class CanonicalEvidenceDto
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly string $definitionId,
        public readonly string $fingerprint,
        public readonly string $title,
        public readonly array $payload,
        public readonly ?int $brandGoalId,
        public readonly ?int $brandOfferingId,
        public readonly bool $generatedByAi,
    ) {}

    public static function fromModel(Evidence $evidence): self
    {
        return new self(
            id: $evidence->id,
            definitionId: (string) $evidence->definition_id,
            fingerprint: (string) $evidence->evidence_fingerprint,
            title: $evidence->title,
            payload: is_array($evidence->payload) ? $evidence->payload : [],
            brandGoalId: $evidence->brand_goal_id,
            brandOfferingId: $evidence->brand_offering_id,
            generatedByAi: (bool) $evidence->generated_by_ai,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'definition_id' => $this->definitionId,
            'fingerprint' => $this->fingerprint,
            'title' => $this->title,
            'payload' => $this->payload,
            'brand_goal_id' => $this->brandGoalId,
            'brand_offering_id' => $this->brandOfferingId,
            'generated_by_ai' => $this->generatedByAi,
        ];
    }
}
