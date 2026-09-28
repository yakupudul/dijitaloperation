<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sorgu hattı for all accounts (daily) or one account right after its pull. */
class RunQueryPipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3500;

    public int $uniqueFor = 3600;

    public function __construct(public ?int $resourceId = null, public bool $force = false) {}

    public function uniqueId(): string
    {
        return 'queries-pipeline:'.($this->resourceId ?? 'all');
    }

    public function handle(QueryPipeline $pipeline): void
    {
        $pipeline->daily($this->resourceId, $this->force);
    }
}
