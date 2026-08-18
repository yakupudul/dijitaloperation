<?php

namespace App\Services\BrandExperiences;

use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Models\BrandExperience;
use App\Support\BrandExperiences\Dto\SectorLearningContributionCandidate;

/**
 * Prompt 53 handoff: build restricted contribution candidates.
 * Does NOT perform privacy qualification, aggregation, or Sector Memory writes.
 */
final class BrandExperienceSectorContributionBuilder
{
    public function fromExperience(BrandExperience $experience): ?SectorLearningContributionCandidate
    {
        $experience->loadMissing('currentRevision');

        $revision = $experience->currentRevision;
        if ($revision === null) {
            return null;
        }

        $structurallyEligible = $experience->status === BrandExperienceStatus::Confirmed
            && in_array(
                $revision->support_status,
                [BrandExperienceSupportStatus::Sufficient, BrandExperienceSupportStatus::Partial],
                true
            );

        return new SectorLearningContributionCandidate(
            experienceId: (int) $experience->id,
            revisionId: (int) $revision->id,
            revisionNumber: (int) $revision->revision_number,
            status: $experience->status->value,
            supportStatus: $revision->support_status->value,
            qualityPolicyVersion: (string) $revision->quality_policy_version,
            marketCode: $revision->market_code,
            channel: $revision->channel?->value,
            actionKind: $revision->action_kind->value,
            outcomeClarity: $revision->outcome_clarity->value,
            actionOccurredAt: $revision->action_occurred_at?->toIso8601String() ?? '',
            outcomeObservedAt: $revision->outcome_observed_at?->toIso8601String() ?? '',
            causalityStatus: $revision->causality_status->value,
            structurallyEligibleForConsideration: $structurallyEligible,
            contributorBrandIdInternal: (int) $experience->brand_id,
            contributorCustomerIdInternal: (int) $experience->customer_id,
        );
    }
}
