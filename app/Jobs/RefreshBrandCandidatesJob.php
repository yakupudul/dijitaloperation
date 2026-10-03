<?php

namespace App\Jobs;

use App\Services\AiTasks\AiTaskQueue;
use App\Services\Portfolio\BrandCandidateBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Keşfedilen varlıklar: groups new discovered resources into brand candidates (daily + "Yeniden grupla"). Delegated to
 * Claude: runs again when Claude has answered every batch.
 */
final class RefreshBrandCandidatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(BrandCandidateBuilder $builder, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self, null, 'Marka adaylarını grupla');
        try {
            $builder->refresh();
        } finally {
            $tasks->settle();
        }
    }
}
