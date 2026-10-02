<?php

namespace App\Jobs\Site;

use App\Models\Brand;
use App\Services\Site\Backlinks\BacklinkSourceProposer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Backlinkler "AI ile kaynak öner": one AI call proposes potential link sources for one brand. */
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

    public function handle(BacklinkSourceProposer $proposer): void
    {
        $brand = Brand::query()->with('customer')->find($this->brandId);
        $result = $brand !== null ? $proposer->propose($brand) : ['status' => 'not_operational', 'added' => 0];
        Cache::put(BacklinkSourceProposer::statusKey($this->brandId), $result, now()->addDay());
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(BacklinkSourceProposer::statusKey($this->brandId), ['status' => 'error', 'added' => 0], now()->addDay());
    }
}
