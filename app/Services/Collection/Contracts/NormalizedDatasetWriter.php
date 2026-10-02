<?php

namespace App\Services\Collection\Contracts;

use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\WriteReceipt;

/**
 * Provider-neutral normalized write port (Prompt 9 boundary; Prompt 10 WarehouseWriter).
 */
interface NormalizedDatasetWriter
{
    public function write(NormalizedDatasetBatch $batch): WriteReceipt;
}
