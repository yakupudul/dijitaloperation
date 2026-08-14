<?php

namespace App\Support\Opportunities\Dto;

use App\Models\Opportunity;

/**
 * Safe Opportunity read DTO. Raw Evidence payloads are not exposed. No magic score field.
 */
final class OpportunityReadDto
{
    /**
     * @param  list<int>  $goalIds
     * @param  list<int>  $offeringIds
     * @param  list<int>  $supportingEvidenceIds
     * @param  list<int>  $supportingFindingIds
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $ruleId,
        public readonly ?int $ruleVersion,
        public readonly string $origin,
        public readonly string $category,
        public readonly ?string $qualitativePriority,
        public readonly string $status,
        public readonly ?string $detectionState,
        public readonly ?string $serviceDefinitionCode,
        public readonly ?string $commercialScopeState,
        public readonly ?int $customerId,
        public readonly ?int $brandId,
        public readonly ?int $digitalAssetId,
        public readonly ?string $subjectKind,
        public readonly ?string $subjectId,
        public readonly array $goalIds,
        public readonly array $offeringIds,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $marketLocation,
        public readonly ?string $marketLanguage,
        public readonly mixed $firstDetectedAt,
        public readonly mixed $lastDetectedAt,
        public readonly mixed $closedAt,
        public readonly ?int $latestEvaluationId,
        public readonly array $supportingEvidenceIds,
        public readonly array $supportingFindingIds,
        public readonly string $fingerprint,
    ) {}

    public static function fromModel(Opportunity $opportunity): self
    {
        $opportunity->loadMissing(['latestEvaluation.evidence', 'latestEvaluation.findings']);

        $evidenceIds = [];
        $findingIds = [];
        if ($opportunity->latestEvaluation !== null) {
            $evidenceIds = $opportunity->latestEvaluation->evidence->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $findingIds = $opportunity->latestEvaluation->findings->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        }

        return new self(
            id: $opportunity->id,
            ruleId: $opportunity->rule_id,
            ruleVersion: $opportunity->rule_version,
            origin: (string) ($opportunity->origin ?? 'legacy_unverified'),
            category: $opportunity->category,
            qualitativePriority: $opportunity->qualitative_priority,
            status: $opportunity->status,
            detectionState: $opportunity->detection_state,
            serviceDefinitionCode: $opportunity->service_definition_code,
            commercialScopeState: $opportunity->commercial_scope_state,
            customerId: $opportunity->customer_id,
            brandId: $opportunity->brand_id,
            digitalAssetId: $opportunity->digital_asset_id,
            subjectKind: $opportunity->subject_kind,
            subjectId: $opportunity->subject_id,
            goalIds: $opportunity->brand_goal_id !== null ? [(int) $opportunity->brand_goal_id] : [],
            offeringIds: $opportunity->brand_offering_id !== null ? [(int) $opportunity->brand_offering_id] : [],
            title: $opportunity->title,
            description: $opportunity->description,
            marketLocation: $opportunity->market_location,
            marketLanguage: $opportunity->market_language,
            firstDetectedAt: $opportunity->first_detected_at,
            lastDetectedAt: $opportunity->last_detected_at,
            closedAt: $opportunity->closed_at,
            latestEvaluationId: $opportunity->latest_evaluation_id,
            supportingEvidenceIds: $evidenceIds,
            supportingFindingIds: $findingIds,
            fingerprint: $opportunity->fingerprint,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rule_id' => $this->ruleId,
            'rule_version' => $this->ruleVersion,
            'origin' => $this->origin,
            'category' => $this->category,
            'qualitative_priority' => $this->qualitativePriority,
            'status' => $this->status,
            'detection_state' => $this->detectionState,
            'service_definition_code' => $this->serviceDefinitionCode,
            'commercial_scope_state' => $this->commercialScopeState,
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'digital_asset_id' => $this->digitalAssetId,
            'subject_kind' => $this->subjectKind,
            'subject_id' => $this->subjectId,
            'goal_ids' => $this->goalIds,
            'offering_ids' => $this->offeringIds,
            'title' => $this->title,
            'description' => $this->description,
            'market_location' => $this->marketLocation,
            'market_language' => $this->marketLanguage,
            'first_detected_at' => $this->firstDetectedAt,
            'last_detected_at' => $this->lastDetectedAt,
            'closed_at' => $this->closedAt,
            'latest_evaluation_id' => $this->latestEvaluationId,
            'supporting_evidence_ids' => $this->supportingEvidenceIds,
            'supporting_finding_ids' => $this->supportingFindingIds,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
