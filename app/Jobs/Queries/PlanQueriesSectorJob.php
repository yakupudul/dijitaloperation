<?php

namespace App\Jobs\Queries;

use App\Models\ServiceCategory;
use App\Services\Ai\AiCancelledException;
use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "AI ile planla" step 2 / 3 for ONE sector: every used sector gets its own job (they run in parallel on the heavy
 * workers); the answer is merged into the operator's proposal (QueryPlanner::mergeSector) and the wizard shows
 * "3 / 7 sektör tamamlandı" while the others still run.
 */
final class PlanQueriesSectorJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 290;

    public int $tries = 1;

    public function __construct(public int $userId, public string $step, public string $run, public int $sectorId, public string $instruction = '')
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryPlanner $planner, QueryNotifier $notifier): void
    {
        $sector = ServiceCategory::query()->find($this->sectorId);
        try {
            $this->record($sector !== null ? $planner->proposeSector($this->step, $sector, $this->instruction) : 'error', $notifier);
        } catch (AiCancelledException $stopped) {
            // Stopped from AI işleri: the sector counts as done (failed) so the step does not wait for it.
            $this->record('cancelled', $notifier);

            throw $stopped;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->record('error', app(QueryNotifier::class));
    }

    /** @param list<array<string, mixed>>|string $result */
    private function record(array|string $result, QueryNotifier $notifier): void
    {
        $status = QueryPlanner::mergeSector($this->userId, $this->step, $this->run, $this->sectorId, $result);
        if ($status === 'error') {
            $notifier->send($this->userId, 'AI adımı başarısız: '.(PlanQueriesJob::LABELS[$this->step] ?? $this->step), route('operator.library.queries.plan', [], false));
        }
    }
}
