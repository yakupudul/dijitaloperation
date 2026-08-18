<?php

namespace App\Services\Collection\Writers;

use App\Services\Collection\Contracts\NormalizedDatasetWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\WriteReceipt;

/**
 * No-op writer — production binds PostgresWarehouseWriter.
 */
final class NullNormalizedDatasetWriter implements NormalizedDatasetWriter
{
    public function write(NormalizedDatasetBatch $batch): WriteReceipt
    {
        return new WriteReceipt(
            writeBatchId: 0,
            status: 'committed',
            rowsReceived: count($batch->records),
            rowsInserted: count($batch->records),
            rowsUpdated: 0,
            rowsUnchanged: 0,
            checkpointSafe: true,
            committedAt: now(),
            reusedExisting: false,
        );
    }
}
