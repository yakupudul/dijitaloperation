<?php

namespace App\Support\IntelligenceRetrieval\Dto;

use App\Enums\IntelligenceSourceAuthority;
use App\Support\SectorLearning\Dto\SectorMemoryConsumerDto;

/**
 * Consumer-safe Sector pattern item for Agent context (Prompt 53 DTO only).
 */
final class SectorPatternContextItem
{
    /**
     * @param  list<string>  $matchReasons
     */
    public function __construct(
        public readonly SectorMemoryConsumerDto $artifact,
        public readonly array $matchReasons,
        public readonly IntelligenceSourceAuthority $authority = IntelligenceSourceAuthority::PrivacyAggregatedSectorContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $base = $this->artifact->toArray();
        // Defense: strip any accidental identity keys
        unset(
            $base['customer_id'],
            $base['brand_id'],
            $base['experience_id'],
            $base['contributor_ids'],
        );

        return [
            'opaque_ref' => 'sector_artifact:'.$this->artifact->artifactStableKey,
            'artifact' => $base,
            'match_reasons' => array_values($this->matchReasons),
            'authority' => $this->authority->value,
            'label' => 'SECTOR_AGGREGATE_CONTEXT',
        ];
    }
}
