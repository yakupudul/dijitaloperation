<?php

namespace App\Support\BrandExperiences\Dto;

/**
 * Restricted Prompt 53 contribution candidate — NOT Sector Memory.
 *
 * Internal pipeline input only. Does not perform privacy qualification or aggregation.
 * Normal Sector consumers must never receive this payload as-is.
 *
 * @phpstan-type CandidateArray array{
 *     experience_id: int,
 *     revision_id: int,
 *     revision_number: int,
 *     status: string,
 *     support_status: string,
 *     quality_policy_version: string,
 *     market_code: string|null,
 *     channel: string|null,
 *     action_kind: string,
 *     outcome_clarity: string,
 *     action_occurred_at: string,
 *     outcome_observed_at: string,
 *     causality_status: string,
 *     structurally_eligible_for_consideration: bool,
 *     privacy_qualified: false,
 *     sector_usable_now: false,
 *     contains_raw_provider_payload: false,
 *     contributor_brand_id_internal: int,
 *     contributor_customer_id_internal: int
 * }
 */
final class SectorLearningContributionCandidate
{
    public function __construct(
        public readonly int $experienceId,
        public readonly int $revisionId,
        public readonly int $revisionNumber,
        public readonly string $status,
        public readonly string $supportStatus,
        public readonly string $qualityPolicyVersion,
        public readonly ?string $marketCode,
        public readonly ?string $channel,
        public readonly string $actionKind,
        public readonly string $outcomeClarity,
        public readonly string $actionOccurredAt,
        public readonly string $outcomeObservedAt,
        public readonly string $causalityStatus,
        public readonly bool $structurallyEligibleForConsideration,
        public readonly int $contributorBrandIdInternal,
        public readonly int $contributorCustomerIdInternal,
    ) {}

    /**
     * @return CandidateArray
     */
    public function toArray(): array
    {
        return [
            'experience_id' => $this->experienceId,
            'revision_id' => $this->revisionId,
            'revision_number' => $this->revisionNumber,
            'status' => $this->status,
            'support_status' => $this->supportStatus,
            'quality_policy_version' => $this->qualityPolicyVersion,
            'market_code' => $this->marketCode,
            'channel' => $this->channel,
            'action_kind' => $this->actionKind,
            'outcome_clarity' => $this->outcomeClarity,
            'action_occurred_at' => $this->actionOccurredAt,
            'outcome_observed_at' => $this->outcomeObservedAt,
            'causality_status' => $this->causalityStatus,
            'structurally_eligible_for_consideration' => $this->structurallyEligibleForConsideration,
            'privacy_qualified' => false,
            'sector_usable_now' => false,
            'contains_raw_provider_payload' => false,
            'contributor_brand_id_internal' => $this->contributorBrandIdInternal,
            'contributor_customer_id_internal' => $this->contributorCustomerIdInternal,
        ];
    }

    /**
     * Consumer-safe view strips contributor identities (Prompt 53 consumer contract).
     *
     * @return array<string, mixed>
     */
    public function toConsumerSafeArray(): array
    {
        $array = $this->toArray();
        unset(
            $array['contributor_brand_id_internal'],
            $array['contributor_customer_id_internal'],
        );

        return $array;
    }
}
