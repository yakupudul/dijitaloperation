<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\Contracts\DatasetExecutor;

final class DatasetExecutorResolver
{
    /**
     * @param  iterable<DatasetExecutor>  $executors
     */
    public function __construct(
        private readonly iterable $executors = [],
    ) {}

    public function resolve(CollectionDatasetRun $datasetRun): DatasetExecutor
    {
        foreach ($this->executors as $executor) {
            if (in_array($datasetRun->request_family_id, $executor->supportedRequestFamilies(), true)) {
                return $executor;
            }
        }

        $level = $datasetRun->requirement_level;
        $message = "No DatasetExecutor registered for request family [{$datasetRun->request_family_id}]";

        throw new UnimplementedDatasetExecutorException(
            $message,
            CollectionErrorCategory::UnimplementedCapability,
        );
    }
}
