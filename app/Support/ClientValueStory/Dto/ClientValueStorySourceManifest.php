<?php

namespace App\Support\ClientValueStory\Dto;

/**
 * References only — Prompt 59 can pin later. No full payload copies.
 */
final class ClientValueStorySourceManifest
{
    /**
     * @param  list<int>  $findingIds
     * @param  list<int>  $opportunityIds
     * @param  list<int>  $taskIds
     * @param  list<int>  $outcomeDefinitionIds
     * @param  list<int>  $outcomeObservationRevisionIds
     * @param  list<string>  $limitationCodes
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $brandId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly array $findingIds,
        public readonly array $opportunityIds,
        public readonly array $taskIds,
        public readonly array $outcomeDefinitionIds,
        public readonly array $outcomeObservationRevisionIds,
        public readonly array $limitationCodes,
        public readonly string $contractVersion = 'client_value_story_manifest_v1',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_version' => $this->contractVersion,
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'period' => [
                'start' => $this->periodStart,
                'end' => $this->periodEnd,
            ],
            'finding_ids' => $this->findingIds,
            'opportunity_ids' => $this->opportunityIds,
            'task_ids' => $this->taskIds,
            'outcome_definition_ids' => $this->outcomeDefinitionIds,
            'outcome_observation_revision_ids' => $this->outcomeObservationRevisionIds,
            'limitation_codes' => $this->limitationCodes,
            'full_payload_copies' => false,
            'attribution_established' => false,
            'causality_established' => false,
            'prompt59_pinnable' => true,
        ];
    }
}
