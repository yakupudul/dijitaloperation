<?php

namespace App\Services\Collection\Writers;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\Contracts\RawPayloadWriter;

final class NullRawPayloadWriter implements RawPayloadWriter
{
    public function write(CollectionDatasetRun $datasetRun, string $payloadRef, array $meta = []): void
    {
        // Prompt 10 implements physical persistence.
    }
}
