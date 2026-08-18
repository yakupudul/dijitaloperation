<?php

namespace App\Support\IntelligenceRetrieval\Dto;

use App\Enums\IntelligenceMatchReason;
use App\Enums\IntelligenceSourceAuthority;

/**
 * Bounded same-Brand historical Experience item for model context.
 */
final class BrandExperienceContextItem
{
    /**
     * @param  list<string>  $matchReasons
     * @param  list<string>  $limitations
     */
    public function __construct(
        public readonly string $opaqueRef,
        public readonly int $experienceRevisionId,
        public readonly int $revisionNumber,
        public readonly ?string $marketCode,
        public readonly ?string $channel,
        public readonly string $actionKind,
        public readonly string $outcomeClarity,
        public readonly string $supportStatus,
        public readonly string $causalityStatus,
        public readonly ?string $actionOccurredAt,
        public readonly ?string $outcomeObservedAt,
        public readonly ?string $boundedSituationSummary,
        public readonly ?string $boundedActionSummary,
        public readonly ?string $boundedOutcomeSummary,
        public readonly array $matchReasons,
        public readonly array $limitations,
        public readonly IntelligenceSourceAuthority $authority = IntelligenceSourceAuthority::HistoricalBrandExperience,
    ) {
        foreach ($this->matchReasons as $reason) {
            if (! is_string($reason) || IntelligenceMatchReason::tryFrom($reason) === null) {
                // Allow string codes that match enum values only.
                if (! is_string($reason)) {
                    throw new \InvalidArgumentException('matchReasons must be strings.');
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'opaque_ref' => $this->opaqueRef,
            'experience_revision_id' => $this->experienceRevisionId,
            'revision_number' => $this->revisionNumber,
            'market_code' => $this->marketCode,
            'channel' => $this->channel,
            'action_kind' => $this->actionKind,
            'outcome_clarity' => $this->outcomeClarity,
            'support_status' => $this->supportStatus,
            'causality_status' => $this->causalityStatus,
            'action_occurred_at' => $this->actionOccurredAt,
            'outcome_observed_at' => $this->outcomeObservedAt,
            'situation_summary' => $this->boundedSituationSummary,
            'action_summary' => $this->boundedActionSummary,
            'outcome_summary' => $this->boundedOutcomeSummary,
            'match_reasons' => array_values($this->matchReasons),
            'limitations' => array_values($this->limitations),
            'authority' => $this->authority->value,
            'label' => 'HISTORICAL_BRAND_EXPERIENCE',
        ];
    }
}
