<?php

namespace App\Services\Collection\Contracts;

use App\Models\Collection\CollectionDatasetRun;

interface NormalizedDatasetWriter
{
    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function write(CollectionDatasetRun $datasetRun, array $records): int;
}
