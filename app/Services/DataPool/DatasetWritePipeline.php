<?php

namespace App\Services\DataPool;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\DataPool\Contracts\WarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\DataPool\Support\RawPayloadReference;
use App\Services\DataPool\Support\WriteReceipt;
use RuntimeException;

/**
 * Canonical safe write order:
 * raw (when required) → normalized validate/write → write-batch commit → WriteReceipt → checkpoint.
 *
 * Object storage is outside the DB transaction.
 */
final class DatasetWritePipeline
{
    public function __construct(
        private readonly RawPayloadWriter $rawWriter,
        private readonly WarehouseWriter $warehouse,
        private readonly DataPoolStorageRegistry $registry,
        private readonly CheckpointManager $checkpoints,
    ) {}

    /**
     * @param  array<string, mixed>|null  $checkpointToAdvance
     */
    public function commit(
        NormalizedDatasetBatch $batch,
        ?RawPayloadEnvelope $rawEnvelope = null,
        ?array $checkpointToAdvance = null,
        bool $rawRequired = false,
    ): WriteReceipt {
        $rawRef = $batch->rawPayloadReference;

        if ($rawEnvelope !== null) {
            try {
                $rawRef = $this->rawWriter->write($rawEnvelope);
            } catch (\Throwable $e) {
                if ($rawRequired || $this->isRawRequired($batch->datasetId)) {
                    throw $e;
                }
                // Optional raw: continue normalized when contract allows.
                $rawRef = null;
            }
        } elseif ($rawRequired || $this->isRawRequired($batch->datasetId)) {
            throw new RuntimeException("Raw payload required for dataset [{$batch->datasetId}] but none provided");
        }

        $batchWithRaw = new NormalizedDatasetBatch(
            datasetId: $batch->datasetId,
            datasetRunId: $batch->datasetRunId,
            contractVersion: $batch->contractVersion,
            batchKey: $batch->batchKey,
            records: $this->recordsWithCanonicalScope($batch, $rawRef),
            digitalAssetId: $batch->digitalAssetId,
            externalResourceId: $batch->externalResourceId,
            collectionRunId: $batch->collectionRunId,
            resourceRunId: $batch->resourceRunId,
            providerOrSource: $batch->providerOrSource,
            rawPayloadReference: $rawRef,
            providerDataTimestamp: $batch->providerDataTimestamp,
            idempotencyKey: $batch->idempotencyKey,
        );

        $receipt = $this->warehouse->write($batchWithRaw);

        if ($checkpointToAdvance !== null && $receipt->isCommitted()) {
            $datasetRun = CollectionDatasetRun::query()->findOrFail($batch->datasetRunId);
            $this->checkpoints->advance($datasetRun, $checkpointToAdvance);
        }

        return $receipt;
    }

    /**
     * Scope carried by NormalizedDatasetBatch is the canonical provider-resource scope.
     * Physical contracts may require these fields in the record itself (including in
     * natural keys), so inject them before warehouse contract validation. This avoids
     * every collector having to duplicate the same scope plumbing and prevents a
     * record from accidentally overriding the batch's authoritative scope.
     *
     * @return list<array<string, mixed>>
     */
    private function recordsWithCanonicalScope(
        NormalizedDatasetBatch $batch,
        ?RawPayloadReference $rawPayloadReference,
    ): array
    {
        if ($batch->records === [] || ! $this->registry->hasPhysicalTable($batch->datasetId)) {
            return $batch->records;
        }

        $physical = $this->registry->physicalDataset($batch->datasetId);
        $columnNames = collect($physical['columns'] ?? [])
            ->filter(fn (mixed $column): bool => is_array($column) && isset($column['name']))
            ->map(fn (array $column): string => (string) $column['name'])
            ->all();

        $scope = [];
        if ($batch->digitalAssetId !== null && in_array('digital_asset_id', $columnNames, true)) {
            $scope['digital_asset_id'] = $batch->digitalAssetId;
        }
        if ($batch->externalResourceId !== null && in_array('external_resource_id', $columnNames, true)) {
            $scope['external_resource_id'] = $batch->externalResourceId;
        }
        if ($rawPayloadReference !== null && in_array('raw_ingestion_object_id', $columnNames, true)) {
            $scope['raw_ingestion_object_id'] = $rawPayloadReference->rawIngestionObjectId;
        }

        if ($scope === []) {
            return $batch->records;
        }

        return array_map(
            static fn (array $record): array => array_merge($record, $scope),
            $batch->records,
        );
    }

    private function isRawRequired(string $datasetId): bool
    {
        $disp = $this->registry->disposition($datasetId);
        if ($disp === null) {
            return false;
        }

        return in_array($disp['disposition'], config('moxdop-data-pool.raw_required_dispositions', ['RAW_ONLY']), true);
    }
}
