<?php

namespace App\Jobs\Site;

use App\Models\BrandClusterSerp;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\Competitors\CompetitorAnalyzer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Rakipler "Analiz et": one AI call compares one cluster's competitor pages with the brand's page (can wait for Claude: the job then runs again). */
final class AnalyzeCompetitorClusterJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 400;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $serpId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->serpId;
    }

    public function handle(CompetitorAnalyzer $analyzer, AiTaskQueue $tasks): void
    {
        $serp = BrandClusterSerp::query()->with(['brand.customer', 'cluster'])->find($this->serpId);
        if ($serp === null) {
            Cache::put(CompetitorAnalyzer::statusKey($this->serpId), ['status' => 'error', 'suggestions' => 0], now()->addDay());

            return;
        }
        $tasks->begin(new self($this->serpId), $serp->brand_id !== null ? (int) $serp->brand_id : null,
            'Rakip analizi · '.trim(($serp->brand?->name ?? '').' · '.($serp->cluster?->name ?? $serp->query), ' ·'));
        try {
            $result = $analyzer->analyze($serp);
        } finally {
            $tasks->settle();
        }
        // Waiting for Claude keeps the tab's "analiz ediliyor…" until the answer comes back.
        Cache::put(CompetitorAnalyzer::statusKey($this->serpId), $result['status'] === 'queued' ? ['status' => 'running'] : $result, now()->addDay());
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(CompetitorAnalyzer::statusKey($this->serpId), ['status' => 'error', 'suggestions' => 0], now()->addDay());
    }
}
