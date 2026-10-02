<?php

namespace App\Events\Collection;

use App\Models\Collection\CollectionRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CollectionRunCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly CollectionRun $collectionRun) {}
}
