<?php

namespace App\Jobs\Site;

use App\Models\Brand;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\Backlinks\BacklinkSourceProposer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Backlinkler "AI ile kaynak öner": one AI call proposes potential link sources for one brand (can wait for Claude: the job then runs again). */
final class ProposeBacklinkSourcesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 400;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $brandId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->brandId;
    }

    public function handle(BacklinkSourceProposer $proposer, AiTaskQueue $tasks): void
    {
        $brand = Brand::query()->with('customer')->find($this->brandId);
        if ($brand === null) {
            Cache::put(BacklinkSourceProposer::statusKey($this->brandId), ['status' => 'not_operational', 'added' => 0], now()->addDay());

            return;
        }
        $tasks->begin(new self($this->brandId), $this->brandId, 'Backlink kaynak önerisi · '.$brand->name);
        try {
            $result = $proposer->propose($brand);
        } finally {
            $tasks->settle();
        }
        // Waiting for Claude keeps the tab's "hazırlanıyor…" until the answer comes back.
        Cache::put(BacklinkSourceProposer::statusKey($this->brandId), $result['status'] === 'queued' ? ['status' => 'running', 'added' => 0] : $result, now()->addDay());
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(BacklinkSourceProposer::statusKey($this->brandId), ['status' => 'error', 'added' => 0], now()->addDay());
    }
}
