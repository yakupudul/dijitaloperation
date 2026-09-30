<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryServiceAssigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "AI ile hizmet öner": the unassigned queries in batches (every batch); before its timeout the job queues itself
 * again from its cursor. When done the operator who asked gets a notification opening the proposal.
 */
final class AssignQueryServicesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1700;

    public int $tries = 1;

    /** @param array{sector: int, after: int} $cursor */
    public function __construct(public int $userId, public ?int $sectorId = null, public array $cursor = ['sector' => 0, 'after' => 0])
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryServiceAssigner $assigner, QueryNotifier $notifier): void
    {
        $next = $assigner->run($this->userId, $this->sectorId, $this->cursor);
        if ($next !== null) {
            self::dispatch($this->userId, $this->sectorId, $next);

            return;
        }
        $state = QueryServiceAssigner::current($this->userId);
        $url = route('operator.library.queries', ['service' => '__none'], false);
        match ($state['status'] ?? null) {
            'ready' => $notifier->send($this->userId, sprintf('Hizmet önerisi hazır: %d sorgu', count((array) $state['items'])), $url),
            'error' => $notifier->send($this->userId, 'AI adımı başarısız: Hizmet öner', $url),
            default => null,
        };
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(QueryServiceAssigner::cacheKey($this->userId), ['status' => 'error', 'items' => [], 'keywords' => []], now()->addDay());
        app(QueryNotifier::class)->send($this->userId, 'AI adımı başarısız: Hizmet öner', route('operator.library.queries', ['service' => '__none'], false));
    }
}
