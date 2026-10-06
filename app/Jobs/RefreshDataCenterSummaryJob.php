<?php

namespace App\Jobs;

use App\Services\DataCenter\DataCenterReader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Veri merkezi: counts the stored rows of every source again (full table aggregations) on the background queue, so the
 * screen reads the counts from the cache instead of scanning the tables on every click. Scheduled hourly.
 */
final class RefreshDataCenterSummaryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(DataCenterReader $reader): void
    {
        $reader->refresh();
    }
}
