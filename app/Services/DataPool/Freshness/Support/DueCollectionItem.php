<?php

namespace App\Services\DataPool\Freshness\Support;

use App\Enums\DataPool\FreshnessState;

final class DueCollectionItem
{
    /**
     * @param  list<string>  $reasons
     * @param  array{start: string, end: string}|null  $dateRange
     */
    public function __construct(
        public readonly int $digitalAssetId,
        public readonly ?int $brandId,
        public readonly ?int $customerId,
        public readonly int $coreAssetBindingId,
        public readonly ?int $externalResourceId,
        public readonly string $providerOrSource,
        public readonly string $datasetId,
        public readonly string $requestFamilyId,
        public readonly FreshnessState $freshnessState,
        public readonly array $reasons,
        public readonly ?array $dateRange,
        public readonly ?string $dueSince,
        public readonly string $priorityCategory,
        public readonly bool $actionRequired,
        public readonly int $policyVersion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'digital_asset_id' => $this->digitalAssetId,
            'brand_id' => $this->brandId,
            'customer_id' => $this->customerId,
            'core_asset_binding_id' => $this->coreAssetBindingId,
            'external_resource_id' => $this->externalResourceId,
            'provider_or_source' => $this->providerOrSource,
            'dataset_id' => $this->datasetId,
            'request_family_id' => $this->requestFamilyId,
            'freshness_state' => $this->freshnessState->value,
            'reasons' => $this->reasons,
            'date_range' => $this->dateRange,
            'due_since' => $this->dueSince,
            'priority_category' => $this->priorityCategory,
            'action_required' => $this->actionRequired,
            'policy_version' => $this->policyVersion,
        ];
    }
}
