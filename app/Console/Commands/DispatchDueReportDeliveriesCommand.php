<?php

namespace App\Console\Commands;

use App\Services\ReportDelivery\ReportDeliveryDispatcher;
use Illuminate\Console\Command;

class DispatchDueReportDeliveriesCommand extends Command
{
    protected $signature = 'reports:dispatch-due-deliveries';

    protected $description = 'Dispatch due report-specific delivery schedule occurrences (Prompt 60)';

    public function handle(ReportDeliveryDispatcher $dispatcher): int
    {
        $ids = $dispatcher->dispatchDue();
        $this->info('Dispatched occurrences: '.count($ids));

        return self::SUCCESS;
    }
}
