<?php

namespace App\Console\Commands;

use App\Services\Intel\MapGridService;
use Illuminate\Console\Command;

/**
 * moxdop:intel:grid — start due map grid scans (opt-in brands, active customers, inside the monthly cap).
 */
final class IntelGridCommand extends Command
{
    protected $signature = 'moxdop:intel:grid';

    protected $description = 'Start scheduled Google Maps grid scans for brands that switched them on.';

    public function handle(MapGridService $grid): int
    {
        $stats = $grid->runDue();
        $this->info(sprintf('Başlatılan %d, atlanan (tavan/kurulum) %d.', $stats['started'], $stats['skipped']));

        return self::SUCCESS;
    }
}
