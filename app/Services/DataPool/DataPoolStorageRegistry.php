<?php

namespace App\Services\DataPool;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads MOXDOP_DATA_POOL_STORAGE_V1 and resolves logical dataset → physical storage.
 * Provider-specific forward-compatible overlays may refine the same typed Data Pool contract
 * without creating a second warehouse architecture.
 */
final class DataPoolStorageRegistry
{
    private ?array $contract = null;

    /** @var array<string, array<string, mixed>> */
    private array $physicalByDataset = [];

    /** @var array<string, array<string, mixed>> */
    private array $dispositionByDataset = [];

    public function __construct(
        private readonly ?string $path = null,
    ) {}

    /** @return array<string, mixed> */
    public function contract(): array
    {
        $this->ensureLoaded();

        return $this->contract;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->contract()['metadata'];
    }

    /** @return array<string, mixed> */
    public function physicalDataset(string $logicalDatasetId): array
    {
        $this->ensureLoaded();

        if (! isset($this->physicalByDataset[$logicalDatasetId])) {
            throw new RuntimeException("No PHYSICAL_TABLE mapping for logical dataset [{$logicalDatasetId}]");
        }

        return $this->physicalByDataset[$logicalDatasetId];
    }

    public function hasPhysicalTable(string $logicalDatasetId): bool
    {
        $this->ensureLoaded();

        return isset($this->physicalByDataset[$logicalDatasetId]);
    }

    /** @return array<string, mixed>|null */
    public function disposition(string $logicalDatasetId): ?array
    {
        $this->ensureLoaded();

        return $this->dispositionByDataset[$logicalDatasetId] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function physicalDatasets(): array
    {
        $this->ensureLoaded();

        return array_values($this->physicalByDataset);
    }

    /** @return list<array<string, mixed>> */
    public function dispositions(): array
    {
        $this->ensureLoaded();

        return array_values($this->dispositionByDataset);
    }

    public function tableName(string $logicalDatasetId): string
    {
        return $this->physicalDataset($logicalDatasetId)['table'];
    }

    private function ensureLoaded(): void
    {
        if ($this->contract !== null) {
            return;
        }

        $path = $this->path ?? config('moxdop-data-pool.storage_contract_path');
        if (! is_string($path) || ! File::exists($path)) {
            throw new RuntimeException('Data pool storage contract not found at '.$path);
        }

        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $meta = $decoded['metadata'] ?? [];
        if (($meta['storage_contract_id'] ?? null) !== config('moxdop-data-pool.storage_contract_id')) {
            throw new RuntimeException('Unsupported storage_contract_id');
        }
        $version = (int) ($meta['version'] ?? 0);
        if (! in_array($version, config('moxdop-data-pool.supported_storage_contract_versions'), true)) {
            throw new RuntimeException("Unsupported storage contract version [{$version}]");
        }

        $this->contract = $decoded;
        foreach ($decoded['physical_datasets'] as $row) {
            $this->physicalByDataset[$row['logical_dataset_id']] = $row;
        }
        foreach ($decoded['dispositions'] as $row) {
            $this->dispositionByDataset[$row['logical_dataset_id']] = $row;
        }

        $this->applyProviderOverlay('moxdop-ga4-central', 'GA4_CENTRAL_RESOURCE_FIRST_V1');
        $this->applyProviderOverlay('moxdop-gsc-central', 'GSC_CENTRAL_RESOURCE_FIRST_V1');
        $this->applyProviderOverlay('moxdop-gbp-central', 'GBP_TYPED_FACTS_V1');
    }

    private function applyProviderOverlay(string $configKey, string $overlayId): void
    {
        /** @var array<string, list<string>> $naturalKeys */
        $naturalKeys = config($configKey.'.natural_key_overrides', []);
        /** @var array<string, list<array<string, mixed>>> $columnAdds */
        $columnAdds = config($configKey.'.columns_add', []);
        /** @var array<string, array<string, mixed>> $additions */
        $additions = config($configKey.'.physical_additions', []);

        foreach ($naturalKeys as $datasetId => $key) {
            if (isset($this->physicalByDataset[$datasetId])) {
                $this->physicalByDataset[$datasetId]['natural_key'] = array_values($key);
            }
        }

        foreach ($columnAdds as $datasetId => $columns) {
            if (! isset($this->physicalByDataset[$datasetId])) {
                continue;
            }

            $existing = $this->physicalByDataset[$datasetId]['columns'] ?? [];
            $byName = [];
            foreach ($existing as $column) {
                if (is_array($column) && isset($column['name'])) {
                    $byName[(string) $column['name']] = $column;
                }
            }
            foreach ($columns as $column) {
                if (isset($column['name'])) {
                    $byName[(string) $column['name']] = $column;
                }
            }
            $this->physicalByDataset[$datasetId]['columns'] = array_values($byName);
        }

        foreach ($additions as $datasetId => $definition) {
            $definition['logical_dataset_id'] = $datasetId;
            $this->physicalByDataset[$datasetId] = $definition;
            $this->dispositionByDataset[$datasetId] = [
                'logical_dataset_id' => $datasetId,
                'disposition' => 'PHYSICAL_TABLE',
                'table' => $definition['table'],
            ];
        }

        // Expose the effective contract to diagnostics without changing the frozen base JSON on disk.
        $this->contract['physical_datasets'] = array_values($this->physicalByDataset);
        $this->contract['dispositions'] = array_values($this->dispositionByDataset);
        $this->contract['metadata']['runtime_overlays'] ??= [];
        if (! in_array($overlayId, $this->contract['metadata']['runtime_overlays'], true)) {
            $this->contract['metadata']['runtime_overlays'][] = $overlayId;
        }
    }
}
