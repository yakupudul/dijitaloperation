<?php

namespace App\Services\Collection\Contracts;

use App\Models\Collection\CollectionDatasetRun;

interface RawPayloadWriter
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function write(CollectionDatasetRun $datasetRun, string $payloadRef, array $meta = []): void;
}
