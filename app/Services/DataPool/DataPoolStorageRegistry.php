<?php

namespace App\Services\DataPool;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads MOXDOP_DATA_POOL_STORAGE_V1 and resolves logical dataset → physical storage.
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

    /**
     * @return array<string, mixed>
     */
    public function contract(): array
    {
        $this->ensureLoaded();

        return $this->contract;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->contract()['metadata'];
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>|null
     */
    public function disposition(string $logicalDatasetId): ?array
    {
        $this->ensureLoaded();

        return $this->dispositionByDataset[$logicalDatasetId] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function physicalDatasets(): array
    {
        return $this->contract()['physical_datasets'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dispositions(): array
    {
        return $this->contract()['dispositions'];
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
    }
}
