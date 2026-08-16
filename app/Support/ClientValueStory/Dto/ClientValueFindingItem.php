<?php

namespace App\Support\ClientValueStory\Dto;

final class ClientValueFindingItem
{
    public function __construct(
        public readonly int $findingId,
        public readonly string $title,
        public readonly string $severity,
        public readonly string $status,
        public readonly ?int $digitalAssetId,
        public readonly ?string $firstSeenAt,
        public readonly ?string $lastSeenAt,
        public readonly ?string $resolvedAt,
        public readonly string $periodRole,
        public readonly ?int $latestEvaluationId,
        public readonly bool $historicalCertaintyLimited = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'finding_id' => $this->findingId,
            'title' => $this->title,
            'severity' => $this->severity,
            'status' => $this->status,
            'digital_asset_id' => $this->digitalAssetId,
            'first_seen_at' => $this->firstSeenAt,
            'last_seen_at' => $this->lastSeenAt,
            'resolved_at' => $this->resolvedAt,
            'period_role' => $this->periodRole,
            'latest_evaluation_id' => $this->latestEvaluationId,
            'historical_certainty_limited' => $this->historicalCertaintyLimited,
            'section' => 'observed',
            'business_impact_claimed' => false,
            'causality_claimed' => false,
        ];
    }
}
