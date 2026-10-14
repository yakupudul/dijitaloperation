<?php

namespace App\Jobs\Brand;

use App\Models\Brand;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Brand\BrandDataReview;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One Claude marka denetimi of one brand, on the background queue (nightly schedule or moxdop:brands:review). The call
 * can wait for Claude (MCP): the job then runs again once Claude answered.
 */
final class RunBrandDataReviewJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(BrandDataReview $review, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self($this->brandId, $this->force), $this->brandId, 'Claude marka denetimi · '.(Brand::query()->whereKey($this->brandId)->value('name') ?? $this->brandId));
        try {
            $review->runSafely($this->brandId, $this->force);
        } finally {
            $tasks->settle();
        }
    }
}
