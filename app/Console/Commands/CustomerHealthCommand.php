<?php

namespace App\Console\Commands;

use App\Services\Portfolio\CustomerHealthScore;
use Illuminate\Console\Command;

/**
 * moxdop:customers:health — recompute the health score of every active customer.
 */
final class CustomerHealthCommand extends Command
{
    protected $signature = 'moxdop:customers:health';

    protected $description = 'Recompute customer health scores (results trend, contact, renewals, alerts).';

    public function handle(CustomerHealthScore $health): int
    {
        $this->info(sprintf('%d müşteri puanlandı.', $health->recomputeAll()['computed']));

        return self::SUCCESS;
    }
}
