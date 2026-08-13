<?php

namespace App\Services\Collection\Writers;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\Contracts\NormalizedDatasetWriter;

final class NullNormalizedDatasetWriter implements NormalizedDatasetWriter
{
    public function write(CollectionDatasetRun $datasetRun, array $records): int
    {
        return count($records);
    }
}
