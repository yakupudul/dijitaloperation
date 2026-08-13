<?php

namespace App\Events\Collection;

use App\Models\Collection\CollectionDatasetRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatasetRunStarted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly CollectionDatasetRun $datasetRun) {}
}
