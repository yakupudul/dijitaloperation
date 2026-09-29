<?php

namespace App\Console\Commands;

use App\Services\Brain\BrainRefresher;
use App\Support\Console\ConsoleScope;
use App\Support\Console\ConsoleScopeException;
use Illuminate\Console\Command;

/** moxdop:brain:refresh — weekly Service Brain calculations on stored data (no provider calls, no AI). */
final class BrainRefreshCommand extends Command
{
    protected $signature = 'moxdop:brain:refresh {--brand= : Marka id veya adının bir parçası (ör. Panorama)}';

    protected $description = 'Service Brain: cannibalization, service chain, page features, success scores and methods (stored data, no AI).';

    public function handle(BrainRefresher $refresher): int
    {
        try {
            $brandId = $this->option('brand') !== null ? ConsoleScope::brand((string) $this->option('brand'))->id : null;
        } catch (ConsoleScopeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        foreach ($refresher->run($brandId) as $step => $result) {
            $this->line($step.': '.$result);
        }

        return self::SUCCESS;
    }
}
