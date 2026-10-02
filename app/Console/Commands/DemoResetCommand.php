<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoState;
use Illuminate\Console\Command;

/**
 * Developer/test helper: forget the process session key used for wizard drafts and flash.
 * This is not a product “Reset Demo” capability and does not seed Atlas fixtures.
 */
final class DemoResetCommand extends Command
{
    protected $signature = 'dop:demo-reset';

    protected $description = 'Forget operator session chrome (wizard draft / flash). Does not seed sample data.';

    public function handle(): int
    {
        DemoState::reset();
        $this->info('Operator session chrome cleared. /app does not seed sample portfolio data.');

        return self::SUCCESS;
    }
}
