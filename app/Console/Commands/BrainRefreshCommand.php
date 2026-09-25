<?php

namespace App\Console\Commands;

use App\Services\Brain\BrainRefresher;
use Illuminate\Console\Command;

/** moxdop:brain:refresh — weekly Service Brain calculations on stored data (no provider calls, no AI). */
final class BrainRefreshCommand extends Command
{
    protected $signature = 'moxdop:brain:refresh {--brand= : Only this brand id}';

    protected $description = 'Service Brain: cannibalization, service chain, page features, success scores and methods (stored data, no AI).';

    public function handle(BrainRefresher $refresher): int
    {
        $brand = $this->option('brand');
        foreach ($refresher->run($brand !== null ? (int) $brand : null) as $step => $result) {
            $this->line($step.': '.$result);
        }

        return self::SUCCESS;
    }
}
