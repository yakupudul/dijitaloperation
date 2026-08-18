<?php

namespace App\Support\SectorLearning\Dto;

/**
 * Deterministic cross-brand-safe contribution projection (Prompt 53).
 *
 * Consumer payloads must never include contributor Brand/Customer/Experience IDs.
 * Internal lineage retains those separately.
 *
 * @phpstan-type SafeProjectionArray array{
 *     projection_version: string,
 *     sector_code: string,
 *     channel: string|null,
 *     market_code: string|null,
 *     action_kind: string,
 *     outcome_clarity: string,
 *     time_bucket: string,
 *     support_status: string,
 *     quality_policy_version: string,
 *     causality_status: string,
 *     contribution_fingerprint: string
 * }
 */
final class SafeSectorContributionProjection
{
    public function __construct(
        public readonly string $projectionVersion,
        public readonly string $sectorCode,
        public readonly ?string $channel,
        public readonly ?string $marketCode,
        public readonly string $actionKind,
        public readonly string $outcomeClarity,
        public readonly string $timeBucket,
        public readonly string $supportStatus,
        public readonly string $qualityPolicyVersion,
        public readonly string $causalityStatus,
        public readonly string $contributionFingerprint,
    ) {}

    /**
     * @return SafeProjectionArray
     */
    public function toConsumerSafeArray(): array
    {
        return [
            'projection_version' => $this->projectionVersion,
            'sector_code' => $this->sectorCode,
            'channel' => $this->channel,
            'market_code' => $this->marketCode,
            'action_kind' => $this->actionKind,
            'outcome_clarity' => $this->outcomeClarity,
            'time_bucket' => $this->timeBucket,
            'support_status' => $this->supportStatus,
            'quality_policy_version' => $this->qualityPolicyVersion,
            'causality_status' => $this->causalityStatus,
            'contribution_fingerprint' => $this->contributionFingerprint,
        ];
    }
}
