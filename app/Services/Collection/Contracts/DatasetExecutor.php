<?php

namespace App\Services\Collection\Contracts;

use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;

interface DatasetExecutor
{
    /**
     * @return list<string>
     */
    public function supportedRequestFamilies(): array;

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult;
}
