<?php

namespace App\Services\Collection\Support;

/**
 * Read-only Google initial-backfill preflight (no analytical provider calls).
 */
final class GoogleBackfillPreflightResult
{
    /**
     * @param  list<array<string, mixed>>  $bindings
     * @param  list<array<string, mixed>>  $connectors
     * @param  list<array<string, mixed>>  $plannedDatasets
     * @param  list<array<string, mixed>>  $alreadySatisfied
     * @param  list<array<string, mixed>>  $dispositions
     * @param  list<array<string, mixed>>  $actionRequired
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly bool $canStart,
        public readonly string $outcome,
        public readonly string $message,
        public readonly array $summary,
        public readonly array $bindings,
        public readonly array $connectors,
        public readonly array $plannedDatasets,
        public readonly array $alreadySatisfied,
        public readonly array $dispositions,
        public readonly array $actionRequired,
        public readonly ?string $fingerprint,
        public readonly int $contractRegistryVersion,
        public readonly string $contractRegistryId,
        public readonly ?int $anchorDigitalAssetId,
        /** @var list<int> */
        public readonly array $eligibleBindingIds,
        public readonly ?int $brandId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'can_start' => $this->canStart,
            'outcome' => $this->outcome,
            'message' => $this->message,
            'summary' => $this->summary,
            'bindings' => $this->bindings,
            'connectors' => $this->connectors,
            'planned_datasets' => $this->plannedDatasets,
            'already_satisfied' => $this->alreadySatisfied,
            'dispositions' => $this->dispositions,
            'action_required' => $this->actionRequired,
            'fingerprint' => $this->fingerprint,
            'contract_registry_version' => $this->contractRegistryVersion,
            'contract_registry_id' => $this->contractRegistryId,
            'anchor_digital_asset_id' => $this->anchorDigitalAssetId,
            'eligible_binding_ids' => $this->eligibleBindingIds,
            'brand_id' => $this->brandId,
        ];
    }
}
