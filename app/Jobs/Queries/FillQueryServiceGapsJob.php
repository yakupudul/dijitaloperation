<?php

namespace App\Jobs\Queries;

use App\Models\ServiceCategory;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Queries\QueryPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Eksik hizmetler (yakup, 2026-10-14): one sector's missing services are proposed from its unassigned queries and added
 * (QueryPlanner::fillServiceGaps). The first run leaves the call in Claude's queue; the run dispatched again after
 * Claude answered adds the services and sends the queries back to triage.
 */
final class FillQueryServiceGapsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $sectorId)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(QueryPlanner $planner, AiTaskQueue $tasks): void
    {
        $sector = ServiceCategory::query()->find($this->sectorId);
        if ($sector === null) {
            return;
        }
        $tasks->begin(new self($this->sectorId), null, 'Eksik hizmetler · '.$sector->name);
        try {
            $result = $planner->fillServiceGaps($sector);
            Log::info('Query service gaps.', ['sector' => $sector->name, 'result' => $result]);
        } finally {
            $tasks->settle();
        }
    }
}
