<?php

namespace App\Services\SectorLearning;

use App\Enums\SectorLearningArtifactStatus;
use App\Enums\SectorPrivacyDisposition;
use App\Models\SectorLearningArtifact;
use App\Support\SectorLearning\Dto\SectorMemoryConsumerDto;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;

/**
 * Consumer-safe Sector Memory reads — released artifacts only.
 *
 * Never returns lineage, contributor IDs, or Brand Experience records.
 */
final class SectorMemoryReadService
{
    /**
     * @return list<SectorMemoryConsumerDto>
     */
    public function listReleasedForSector(string $sectorCode, int $limit = 20): array
    {
        $artifacts = SectorLearningArtifact::query()
            ->with('currentRevision')
            ->where('sector_code', $sectorCode)
            ->where('status', SectorLearningArtifactStatus::Active)
            ->orderByDesc('updated_at')
            ->limit(max(1, min(50, $limit)))
            ->get();

        $out = [];
        foreach ($artifacts as $artifact) {
            $dto = $this->toConsumerDto($artifact);
            if ($dto !== null) {
                $out[] = $dto;
            }
        }

        return $out;
    }

    public function findReleasedByStableKey(string $stableKey): ?SectorMemoryConsumerDto
    {
        $artifact = SectorLearningArtifact::query()
            ->with('currentRevision')
            ->where('stable_key', $stableKey)
            ->where('status', SectorLearningArtifactStatus::Active)
            ->first();

        return $artifact !== null ? $this->toConsumerDto($artifact) : null;
    }

    private function toConsumerDto(SectorLearningArtifact $artifact): ?SectorMemoryConsumerDto
    {
        $revision = $artifact->currentRevision;
        if ($revision === null || $revision->status !== SectorLearningArtifactStatus::Active) {
            return null;
        }

        if ($revision->privacy_policy_version !== SectorLearningPrivacyPolicy::VERSION) {
            // Stricter/current policy mismatch → not consumer-eligible until requalified.
            return null;
        }

        $assessment = is_array($revision->privacy_assessment) ? $revision->privacy_assessment : [];
        $disposition = SectorPrivacyDisposition::tryFrom((string) ($assessment['disposition'] ?? ''))
            ?? SectorPrivacyDisposition::BlockedPrivacyNotQualified;

        if (! $disposition->isEligible()) {
            return null;
        }

        $aggregate = is_array($revision->aggregate_result) ? $revision->aggregate_result : [];
        // Defense-in-depth: strip any accidental identity keys
        foreach (SectorLearningPrivacyPolicy::BLOCKED_IDENTIFIER_KEYS as $key) {
            unset($aggregate[$key]);
        }

        return new SectorMemoryConsumerDto(
            artifactStableKey: $artifact->stable_key,
            artifactId: (int) $artifact->id,
            revisionId: (int) $revision->id,
            revisionNumber: (int) $revision->revision_number,
            sectorCode: $artifact->sector_code,
            artifactKind: $artifact->artifact_kind,
            dimensionContract: is_array($revision->dimension_contract) ? $revision->dimension_contract : [],
            timeScope: is_array($revision->time_scope) ? $revision->time_scope : [],
            actionCategory: $revision->action_category,
            metricFamily: $revision->metric_family,
            aggregateResult: $aggregate,
            cohortBand: $revision->cohort_band,
            limitations: is_array($revision->limitations) ? array_values($revision->limitations) : [],
            privacyPolicyVersion: $revision->privacy_policy_version,
            aggregationMethodVersion: $revision->aggregation_method_version,
            projectionVersion: $revision->projection_version,
            observationalLabel: $revision->observational_label,
            summaryText: $revision->summary_text,
            privacyDisposition: $disposition,
            privacyReasonCodes: array_values(array_map('strval', $assessment['reason_codes'] ?? [])),
            updatedAt: $revision->updated_at?->toIso8601String() ?? '',
        );
    }
}
