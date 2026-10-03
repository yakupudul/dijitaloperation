<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Portfolio\BrandServiceExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Hizmet keşfi: one AI call proposes the brand's services from its own pages (delegated: runs again with Claude's answer). */
final class ExtractBrandServicesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

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

    public function handle(BrandServiceExtractor $extractor, AiTaskQueue $tasks): void
    {
        $brand = Brand::query()->find($this->brandId);
        if ($brand === null) {
            return;
        }
        $tasks->begin(new self($this->brandId), (int) $brand->id, 'Hizmet keşfi · '.$brand->name);
        try {
            $extractor->extract($brand);
        } finally {
            $tasks->settle();
        }
    }
}
