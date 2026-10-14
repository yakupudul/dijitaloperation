<?php

namespace App\Jobs\Queries;

use App\Services\AiTasks\AiTaskQueue;
use App\Services\Queries\QueryAutopilot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sorgu otomatik pilotu, one triage batch given to Claude over MCP (QueryAutopilot::queueForClaude): the first run
 * leaves the batch in Claude's queue, the run dispatched again after Claude answered applies the answer.
 */
final class TriageQueriesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /** @param list<int> $queryIds */
    public function __construct(public string $batch, public int $sectorId, public array $queryIds)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(QueryAutopilot $autopilot, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self($this->batch, $this->sectorId, $this->queryIds), null, 'Sorgu otomatik pilotu · '.count($this->queryIds).' sorgu');
        try {
            $autopilot->triageBatch($this->batch, $this->sectorId, $this->queryIds);
        } finally {
            $tasks->settle();
        }
    }
}
