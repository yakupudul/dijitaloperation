<?php

namespace App\Jobs\Site;

use App\Models\Brand;
use App\Models\Cluster;
use App\Models\User;
use App\Services\Site\ContentIdeaPool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** "Yeni fikir üret": one `content.ideas` call for a cluster; the result waits in the cluster's cache key for the screen. */
final class GenerateContentIdeasJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $clusterId, public ?int $brandId, public int $count, public ?int $userId = null) {}

    public function handle(ContentIdeaPool $pool): void
    {
        $cluster = Cluster::query()->find($this->clusterId);
        if ($cluster === null) {
            Cache::forget(ContentIdeaPool::cacheKey($this->clusterId));

            return;
        }
        $result = $pool->generate($cluster, $this->brandId !== null ? Brand::query()->find($this->brandId) : null, $this->count,
            $this->userId !== null ? User::query()->find($this->userId) : null);
        Cache::put(ContentIdeaPool::cacheKey($this->clusterId), ['status' => $result['status'] === 'ready' ? 'done' : $result['status']] + $result, now()->addDay());
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(ContentIdeaPool::cacheKey($this->clusterId), ['status' => 'error', 'added' => 0, 'rejected' => []], now()->addDay());
    }
}
