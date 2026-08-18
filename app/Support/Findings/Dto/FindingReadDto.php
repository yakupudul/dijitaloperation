<?php

namespace App\Support\Findings\Dto;

use App\Models\Finding;

/**
 * Safe Finding read DTO. Raw Evidence payloads are not exposed.
 */
final class FindingReadDto
{
    /**
     * @param  list<int>  $supportingEvidenceIds
     * @param  list<int>  $goalIds
     * @param  list<int>  $offeringIds
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $ruleId,
        public readonly ?int $ruleVersion,
        public readonly string $origin,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $status,
        public readonly ?string $conditionState,
        public readonly ?int $customerId,
        public readonly ?int $brandId,
        public readonly int $digitalAssetId,
        public readonly ?string $subjectKind,
        public readonly ?string $subjectId,
        public readonly array $goalIds,
        public readonly array $offeringIds,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly mixed $firstDetectedAt,
        public readonly mixed $lastDetectedAt,
        public readonly mixed $resolvedAt,
        public readonly ?int $latestEvaluationId,
        public readonly array $supportingEvidenceIds,
        public readonly string $fingerprint,
    ) {}

    public static function fromModel(Finding $finding): self
    {
        $finding->loadMissing('latestEvaluation.evidence');
        $evidenceIds = [];
        if ($finding->latestEvaluation !== null) {
            $evidenceIds = $finding->latestEvaluation->evidence->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        }

        return new self(
            id: $finding->id,
            ruleId: $finding->rule_id,
            ruleVersion: $finding->rule_version,
            origin: (string) ($finding->origin ?? 'legacy_unverified'),
            category: $finding->category,
            severity: $finding->severity,
            status: $finding->status,
            conditionState: $finding->condition_state,
            customerId: $finding->customer_id,
            brandId: $finding->brand_id,
            digitalAssetId: $finding->digital_asset_id,
            subjectKind: $finding->subject_kind,
            subjectId: $finding->subject_id,
            goalIds: $finding->brand_goal_id !== null ? [(int) $finding->brand_goal_id] : [],
            offeringIds: $finding->brand_offering_id !== null ? [(int) $finding->brand_offering_id] : [],
            title: $finding->title,
            summary: $finding->summary,
            firstDetectedAt: $finding->first_seen_at,
            lastDetectedAt: $finding->last_seen_at,
            resolvedAt: $finding->resolved_at,
            latestEvaluationId: $finding->latest_evaluation_id,
            supportingEvidenceIds: $evidenceIds,
            fingerprint: $finding->fingerprint,
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
            'severity' => $this->severity,
            'status' => $this->status,
            'condition_state' => $this->conditionState,
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'digital_asset_id' => $this->digitalAssetId,
            'subject_kind' => $this->subjectKind,
            'subject_id' => $this->subjectId,
            'goal_ids' => $this->goalIds,
            'offering_ids' => $this->offeringIds,
            'title' => $this->title,
            'summary' => $this->summary,
            'first_detected_at' => $this->firstDetectedAt,
            'last_detected_at' => $this->lastDetectedAt,
            'resolved_at' => $this->resolvedAt,
            'latest_evaluation_id' => $this->latestEvaluationId,
            'supporting_evidence_ids' => $this->supportingEvidenceIds,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
