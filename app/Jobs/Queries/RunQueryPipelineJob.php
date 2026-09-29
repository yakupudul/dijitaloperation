<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sorgu hattı for all accounts (daily) or one account right after its pull — the orchestrator only. It takes the
 * pipeline lock and queues a chain of short, bounded steps on the heavy queue:
 *
 *   IngestQuerySourcesJob × ⌈accounts / sources_per_job⌉ → AssignQuerySectorsJob → MatchQueriesJob →
 *   ClassifyUnmatchedQueriesJob (AI, capped per job, continues itself) → FinishQueryPipelineJob.
 *
 * The old single job ran every account plus all AI calls in one process with a 3 500 s timeout, far above the
 * queue's retry_after (900 s): Redis handed the still-running job to a second worker and the second attempt failed
 * with MaxAttemptsExceededException. Each step here finishes well under the queue timeouts and is idempotent
 * (unchanged accounts are skipped by their facts fingerprint), so a retry or a re-run is safe.
 */
class RunQueryPipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    /** One pending orchestrator per scope (all accounts / one account). */
    public int $uniqueFor = 3600;

    public function __construct(public ?int $resourceId = null, public bool $force = false)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'queries-pipeline:'.($this->resourceId ?? 'all');
    }

    public function handle(QueryPipeline $pipeline): void
    {
        $lock = Cache::lock(QueryPipeline::lockKey($this->resourceId), 3600);
        if (! $lock->get()) {
            Log::info('queries.pipeline.skipped-running', ['resource_id' => $this->resourceId]);

            return;
        }
        try {
            $keys = $pipeline->sourceKeys($this->resourceId);
            $runId = (string) Str::uuid();
            $perJob = max(1, (int) config('moxdop-queries.sources_per_job', 10));
            $jobs = [];
            foreach (array_chunk($keys, $perJob) as $chunk) {
                $jobs[] = new IngestQuerySourcesJob($runId, $chunk, $this->force);
            }
            $jobs[] = new AssignQuerySectorsJob($runId, $this->resourceId);
            $jobs[] = new MatchQueriesJob($runId);
            $jobs[] = new ClassifyUnmatchedQueriesJob($runId, max(0, (int) config('moxdop-queries.classify_per_run', 600)));
            $jobs[] = new FinishQueryPipelineJob($runId, $this->resourceId, $lock->owner(), count($keys));

            $scope = $this->resourceId;
            $owner = $lock->owner();
            Bus::chain($jobs)
                ->onQueue((string) config('queue.heavy_queue', 'default'))
                ->catch(function (Throwable $exception) use ($scope, $owner): void {
                    // A failed step ends the run; the next run starts clean (steps are idempotent).
                    Cache::restoreLock(QueryPipeline::lockKey($scope), $owner)->release();
                })
                ->dispatch();
        } catch (Throwable $exception) {
            $lock->release();

            throw $exception;
        }
    }
}
