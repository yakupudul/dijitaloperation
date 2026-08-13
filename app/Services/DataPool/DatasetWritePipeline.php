<?php

namespace App\Services\DataPool;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\DataPool\Contracts\WarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
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
            records: $batch->records,
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

    private function isRawRequired(string $datasetId): bool
    {
        $disp = $this->registry->disposition($datasetId);
        if ($disp === null) {
            return false;
        }

        return in_array($disp['disposition'], config('moxdop-data-pool.raw_required_dispositions', ['RAW_ONLY']), true);
    }
}
