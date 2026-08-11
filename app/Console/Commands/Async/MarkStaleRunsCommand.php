<?php

namespace App\Console\Commands\Async;

use App\Services\Async\AsyncOperationService;
use Illuminate\Console\Command;

class MarkStaleRunsCommand extends Command
{
    protected $signature = 'async:mark-stale-runs';

    protected $description = 'Mark async orchestration Runs as needing attention when progress heartbeats go stale';

    public function handle(AsyncOperationService $operations): int
    {
        $count = $operations->markStaleRuns();
        $this->info("Marked {$count} stale async run(s).");

        return self::SUCCESS;
    }
}
