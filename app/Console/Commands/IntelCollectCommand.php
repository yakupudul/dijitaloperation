<?php

namespace App\Console\Commands;

use App\Services\Intel\DataForSeoTaskQueue;
use Illuminate\Console\Command;

/**
 * moxdop:intel:collect — read finished DataForSEO queued tasks (map grid, reviews, prospect maps) and store them.
 */
final class IntelCollectCommand extends Command
{
    protected $signature = 'moxdop:intel:collect';

    protected $description = 'Collect results of queued DataForSEO tasks (free reads) and hand them to their feature.';

    public function handle(DataForSeoTaskQueue $queue): int
    {
        $stats = $queue->collect();
        $this->info(sprintf('Tamamlanan %d, bekleyen %d, başarısız %d.', $stats['completed'], $stats['waiting'], $stats['failed']));

        return self::SUCCESS;
    }
}
