<?php

namespace App\Services\Collection\Support;

use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;

final class DatasetExecutionContext
{
    /**
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, mixed>  $registryDataset
     * @param  array<string, mixed>  $registryRequestFamily
     */
    public function __construct(
        public readonly CollectionRun $collectionRun,
        public readonly CollectionResourceRun $resourceRun,
        public readonly CollectionDatasetRun $datasetRun,
        public readonly array $checkpoint,
        public readonly array $registryDataset,
        public readonly array $registryRequestFamily,
        public readonly int $attemptNumber,
    ) {}
}
