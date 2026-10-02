<?php

namespace App\Services\Collection\Support;

/**
 * Read-only Meta initial-backfill preflight (no analytical Marketing API calls).
 */
final class MetaBackfillPreflightResult
{
    /**
     * @param  list<array<string, mixed>>  $bindings
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<array<string, mixed>>  $plannedDatasets
     * @param  list<array<string, mixed>>  $alreadySatisfied
     * @param  list<array<string, mixed>>  $dispositions
     * @param  list<array<string, mixed>>  $actionRequired
     * @param  array<string, mixed>  $summary
     * @param  list<int>  $eligibleBindingIds
     */
    public function __construct(
        public readonly bool $canStart,
        public readonly string $outcome,
        public readonly string $message,
        public readonly array $summary,
        public readonly array $bindings,
        public readonly array $accounts,
        public readonly array $plannedDatasets,
        public readonly array $alreadySatisfied,
        public readonly array $dispositions,
        public readonly array $actionRequired,
        public readonly ?string $fingerprint,
        public readonly int $contractRegistryVersion,
        public readonly string $contractRegistryId,
        public readonly ?int $anchorDigitalAssetId,
        public readonly array $eligibleBindingIds,
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
            'accounts' => $this->accounts,
            'planned_datasets' => $this->plannedDatasets,
            'already_satisfied' => $this->alreadySatisfied,
            'dispositions' => $this->dispositions,
            'action_required' => $this->actionRequired,
            'fingerprint' => $this->fingerprint,
            'contract_registry_version' => $this->contractRegistryVersion,
            'contract_registry_id' => $this->contractRegistryId,
            'anchor_digital_asset_id' => $this->anchorDigitalAssetId,
            'eligible_binding_ids' => $this->eligibleBindingIds,
        ];
    }
}
