<?php

namespace App\Services\Collection\Contracts;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionDatasetRun;

interface RetryPolicy
{
    public function shouldRetry(
        CollectionDatasetRun $datasetRun,
        CollectionErrorCategory $category,
        int $attemptNumber,
    ): bool;

    public function backoffSeconds(CollectionDatasetRun $datasetRun, int $attemptNumber): int;
}
