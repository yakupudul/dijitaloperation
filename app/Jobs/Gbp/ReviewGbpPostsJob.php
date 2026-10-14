<?php

namespace App\Jobs\Gbp;

use App\Models\Brand;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Gbp\GbpPostReview;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Günde 1 tık: the AI check of one brand's next Business Profile posts (GbpPostReview). On the content queue; when the
 * Claude API credit is used up Claude (MCP) answers and the job runs again.
 */
final class ReviewGbpPostsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public int $brandId)
    {
        $this->onQueue((string) config('queue.content_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->brandId;
    }

    public function handle(GbpPostReview $review, AiTaskQueue $tasks): void
    {
        $brand = Brand::query()->find($this->brandId);
        if ($brand === null) {
            return;
        }
        $tasks->begin(new self($this->brandId), $this->brandId, 'İşletme Profili gönderi kontrolü · '.$brand->name);
        try {
            $review->review($this->brandId);
        } finally {
            $tasks->settle();
        }
    }
}
