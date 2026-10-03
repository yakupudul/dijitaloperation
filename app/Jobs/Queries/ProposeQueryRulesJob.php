<?php

namespace App\Jobs\Queries;

use App\Services\AiTasks\AiTaskQueue;
use App\Services\Queries\QueryRuleProposer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** "AI ile kural üret": one AI call over the selected queries; the proposal waits for the operator who asked. */
final class ProposeQueryRulesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /** @param list<int> $queryIds */
    public function __construct(public int $userId, public array $queryIds)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryRuleProposer $proposer, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self($this->userId, $this->queryIds), null, 'Sorgu kuralları · '.count($this->queryIds).' sorgu');
        try {
            $result = $proposer->propose($this->queryIds);
        } finally {
            $tasks->settle();
        }
        if ($result['status'] === 'queued') {
            // Waiting for Claude (MCP queue): this job runs again with the answer.
            Cache::put(QueryRuleProposer::cacheKey($this->userId), ['status' => 'running', 'waiting' => true], now()->addDays(5));

            return;
        }
        Cache::put(QueryRuleProposer::cacheKey($this->userId), $result, now()->addDay());
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(QueryRuleProposer::cacheKey($this->userId), ['status' => 'error', 'terms' => [], 'keywords' => []], now()->addDay());
    }
}
