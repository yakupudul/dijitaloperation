<?php

namespace App\Jobs\Brand;

use App\Models\Brand;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Brand\BrandCare;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One Marka bakım ajanı review of one brand, on the background queue (weekly schedule or the tab's button). The review
 * can wait for Claude (MCP): the job then runs again once Claude answered.
 */
final class RunBrandCareJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $brandId, public bool $force = false)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->brandId;
    }

    public function handle(BrandCare $care, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self($this->brandId, $this->force), $this->brandId, 'Marka bakım ajanı · '.(Brand::query()->whereKey($this->brandId)->value('name') ?? $this->brandId));
        try {
            $care->runSafely($this->brandId, $this->force);
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        BrandCare::markFailed($this->brandId, 'İnceleme tamamlanamadı; tekrar deneyin.');
    }
}
