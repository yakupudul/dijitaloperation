<?php

namespace App\Support\SectorLearning\Dto;

use App\Enums\SectorLearningArtifactKind;
use App\Enums\SectorLearningCohortBand;
use App\Enums\SectorPrivacyDisposition;

/**
 * Consumer-safe Sector Learning artifact view (Prompt 53 / Prompt 54 handoff).
 *
 * MUST NOT contain Customer/Brand/Experience IDs or contributor lists.
 */
final class SectorMemoryConsumerDto
{
    /**
     * @param  array<string, mixed>  $dimensionContract
     * @param  array<string, mixed>  $timeScope
     * @param  array<string, mixed>  $aggregateResult
     * @param  list<string>  $limitations
     * @param  list<string>  $privacyReasonCodes
     */
    public function __construct(
        public readonly string $artifactStableKey,
        public readonly int $artifactId,
        public readonly int $revisionId,
        public readonly int $revisionNumber,
        public readonly string $sectorCode,
        public readonly SectorLearningArtifactKind $artifactKind,
        public readonly array $dimensionContract,
        public readonly array $timeScope,
        public readonly ?string $actionCategory,
        public readonly ?string $metricFamily,
        public readonly array $aggregateResult,
        public readonly SectorLearningCohortBand $cohortBand,
        public readonly array $limitations,
        public readonly string $privacyPolicyVersion,
        public readonly string $aggregationMethodVersion,
        public readonly string $projectionVersion,
        public readonly string $observationalLabel,
        public readonly string $summaryText,
        public readonly SectorPrivacyDisposition $privacyDisposition,
        public readonly array $privacyReasonCodes,
        public readonly string $updatedAt,
    ) {
        foreach (['customer_id', 'brand_id', 'experience_id', 'contributor_ids', 'brand_ids', 'customer_ids'] as $forbidden) {
            if (array_key_exists($forbidden, $this->aggregateResult)
                || array_key_exists($forbidden, $this->dimensionContract)) {
                throw new \InvalidArgumentException(
                    "SectorMemoryConsumerDto must not contain {$forbidden}."
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_stable_key' => $this->artifactStableKey,
            'artifact_id' => $this->artifactId,
            'revision_id' => $this->revisionId,
            'revision_number' => $this->revisionNumber,
            'sector_code' => $this->sectorCode,
            'artifact_kind' => $this->artifactKind->value,
            'dimension_contract' => $this->dimensionContract,
            'time_scope' => $this->timeScope,
            'action_category' => $this->actionCategory,
            'metric_family' => $this->metricFamily,
            'aggregate_result' => $this->aggregateResult,
            'cohort_band' => $this->cohortBand->value,
            'limitations' => $this->limitations,
            'privacy_policy_version' => $this->privacyPolicyVersion,
            'aggregation_method_version' => $this->aggregationMethodVersion,
            'projection_version' => $this->projectionVersion,
            'observational_label' => $this->observationalLabel,
            'summary_text' => $this->summaryText,
            'privacy_disposition' => $this->privacyDisposition->value,
            'privacy_reason_codes' => array_values($this->privacyReasonCodes),
            'updated_at' => $this->updatedAt,
            'source_label' => 'moxdop_cohort_observation',
            'causality_status' => 'causality_not_established',
            'industry_benchmark_claim' => false,
        ];
    }

    /**
     * Prompt 54 Memory Pack item shape (references only; no retrieval injection here).
     *
     * @return array{artifact_id: string, revision: string, citation: string|null}
     */
    public function toMemoryPackReference(): array
    {
        return [
            'artifact_id' => $this->artifactStableKey,
            'revision' => (string) $this->revisionNumber,
            'citation' => $this->summaryText,
        ];
    }
}
