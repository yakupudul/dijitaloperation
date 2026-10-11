<?php

namespace App\Jobs\Queries;

use App\Services\Catalog\ServiceCatalogCleaner;
use App\Services\Queries\QueryAutopilot;
use App\Services\Queries\QueryClusterQueue;
use App\Services\Queries\QueryFinish;
use App\Services\Work\ContentCoverage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One stage of `moxdop:queries:finish`; each stage queues the next: clean (catalog clean-up, triage on the Claude API
 * while its window is open, unassigned queries asked again) → triage (autopilot ticks until nothing is left) →
 * cluster (clustering without the daily gate) → wait (until the clustering queue ends) → content (the title run of
 * every site, with the weekly fresh ideas) → check (counts for the queries page). AI calls go through the usual route
 * and AiBudget: a blocked budget ends the triage stage like an empty queue.
 */
final class FinishQueriesJob implements ShouldQueue
{
    use Queueable;

    public const array STAGES = ['clean', 'triage', 'cluster', 'wait', 'content', 'check'];

    /** Triage ticks at most (each up to 10 minutes); the 15-minute autopilot schedule goes on after it. */
    private const int MAX_TICKS = 500;

    /** Waiting rounds for the clustering queue (5 minutes each: 2 days). */
    private const int MAX_WAITS = 576;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $stage = 'clean', public int $round = 0)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(QueryAutopilot $autopilot, ServiceCatalogCleaner $cleaner): void
    {
        QueryFinish::record(['stage' => $this->stage, 'round' => $this->round]);
        match ($this->stage) {
            'clean' => $this->clean($cleaner),
            'triage' => $this->triage($autopilot),
            'cluster' => $this->cluster($autopilot),
            'wait' => $this->waitForClustering(),
            'content' => $this->content(),
            default => $this->check(),
        };
    }

    public function failed(?Throwable $exception): void
    {
        QueryFinish::record(['stage' => 'done', 'error' => $this->stage.': '.mb_substr((string) $exception?->getMessage(), 0, 300)]);
        report($exception);
    }

    private function clean(ServiceCatalogCleaner $cleaner): void
    {
        $moved = QueryFinish::triageOnClaudeApi();
        $summary = $cleaner->clean();
        $reset = QueryFinish::resetUnassigned();
        QueryFinish::record(['triage_moved' => $moved, 'archived' => $summary['archived'], 'merged' => $summary['merged'], 'reset' => $reset]);
        self::dispatch('triage');
    }

    private function triage(QueryAutopilot $autopilot): void
    {
        $lock = Cache::lock('queries:autopilot:tick', $this->timeout);
        if (! $lock->get()) {
            self::dispatch('triage', $this->round + 1)->delay(now()->addMinute()); // the scheduled tick is running

            return;
        }
        try {
            $result = $autopilot->tick();
        } finally {
            $lock->release();
        }
        QueryFinish::record(['triage' => $result]);
        if (in_array($result, ['more', 'imported'], true) && $this->round < self::MAX_TICKS) {
            self::dispatch('triage', $this->round + 1);

            return;
        }
        self::dispatch('cluster');
    }

    private function cluster(QueryAutopilot $autopilot): void
    {
        QueryFinish::record(['clustering' => $autopilot->clusterIfDue(ignoreGate: true) || QueryClusterQueue::running()]);
        self::dispatch('wait');
    }

    private function waitForClustering(): void
    {
        if (QueryClusterQueue::running() && ! QueryClusterQueue::stale() && $this->round < self::MAX_WAITS) {
            self::dispatch('wait', $this->round + 1)->delay(now()->addMinutes(5));

            return;
        }
        self::dispatch('content');
    }

    private function content(): void
    {
        if (ContentCoverage::automaticAllowed()) {
            try {
                Artisan::call('moxdop:content:weekly-titles', ['--weekly' => true]);
                QueryFinish::record(['content' => 'started']);
            } catch (Throwable $exception) {
                Log::warning('queries.finish: content titles could not start.', ['error' => $exception->getMessage()]);
                QueryFinish::record(['content' => 'error']);
            }
        } else {
            QueryFinish::record(['content' => 'budget']);
        }
        self::dispatch('check');
    }

    private function check(): void
    {
        $check = QueryFinish::check();
        QueryFinish::record(['stage' => 'done', 'finished_at' => now()->toIso8601String()]);
        Log::info('queries.finish', $check);
    }
}
