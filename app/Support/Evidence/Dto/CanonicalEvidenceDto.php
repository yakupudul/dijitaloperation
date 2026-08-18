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
        public readonly int $digitalAssetId,
        public readonly string $definitionId,
        public readonly string $fingerprint,
        public readonly string $title,
        public readonly array $payload,
        public readonly ?int $brandGoalId,
        public readonly ?int $brandOfferingId,
        public readonly bool $generatedByAi,
        public readonly ?string $freshnessState = null,
        public readonly ?string $integrityStatus = null,
        public readonly ?string $eligibilityStatus = null,
        public readonly ?string $observedAt = null,
    ) {}

    public static function fromModel(Evidence $evidence): self
    {
        $payload = is_array($evidence->payload) ? $evidence->payload : [];

        return new self(
            id: $evidence->id,
            digitalAssetId: (int) $evidence->digital_asset_id,
            definitionId: (string) $evidence->definition_id,
            fingerprint: (string) $evidence->evidence_fingerprint,
            title: $evidence->title,
            payload: $payload,
            brandGoalId: $evidence->brand_goal_id,
            brandOfferingId: $evidence->brand_offering_id,
            generatedByAi: (bool) $evidence->generated_by_ai,
            freshnessState: isset($payload['freshness_state']) ? (string) $payload['freshness_state'] : null,
            integrityStatus: isset($payload['integrity_status']) ? (string) $payload['integrity_status'] : null,
            eligibilityStatus: $evidence->eligibility_status !== null ? (string) $evidence->eligibility_status : null,
            observedAt: $evidence->observed_at?->toIso8601String(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'digital_asset_id' => $this->digitalAssetId,
            'definition_id' => $this->definitionId,
            'fingerprint' => $this->fingerprint,
            'title' => $this->title,
            'payload' => $this->payload,
            'brand_goal_id' => $this->brandGoalId,
            'brand_offering_id' => $this->brandOfferingId,
            'generated_by_ai' => $this->generatedByAi,
            'freshness_state' => $this->freshnessState,
            'integrity_status' => $this->integrityStatus,
        ];
    }
}
