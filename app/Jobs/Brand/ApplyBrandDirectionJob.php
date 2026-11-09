<?php

namespace App\Jobs\Brand;

use App\Models\BrandDirectionChange;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Brand\BrandDirection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Marka rotası: realigns one brand to a direction change (BrandDirection::apply). The fit check can wait for Claude
 * (MCP): the job then runs again once Claude answered and continues where it stopped.
 */
final class ApplyBrandDirectionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $changeId)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->changeId;
    }

    public function handle(BrandDirection $direction, AiTaskQueue $tasks): void
    {
        $change = BrandDirectionChange::query()->find($this->changeId);
        if ($change === null || $change->applied_at !== null) {
            return;
        }
        $tasks->begin(new self($this->changeId), (int) $change->brand_id, 'Marka rotası · küme ve fikir uyumu');
        try {
            $direction->apply($change);
        } finally {
            $tasks->settle();
        }
    }
}
