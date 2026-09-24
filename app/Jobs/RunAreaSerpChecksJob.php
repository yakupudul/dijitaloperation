<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Demand\AreaSerpChecker;
use App\Services\Demand\CompetitorPageComparator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Şimdi kontrol et" on the brand demand section: the capped area SERP run plus the competitor comparison. */
class RunAreaSerpChecksJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public int $brandId) {}

    public function handle(AreaSerpChecker $checker, CompetitorPageComparator $comparator): void
    {
        $brand = Brand::query()->find($this->brandId);
        if ($brand !== null) {
            $checker->run($brand);
            $comparator->run($brand);
        }
    }
}
