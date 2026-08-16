<?php

namespace App\Console\Commands;

use App\Enums\RecurringScheduleKind;
use App\Services\RecurringAutomation\RecurringAutomationDispatcher;
use Illuminate\Console\Command;

/**
 * Prompt 60 compatibility command — converges onto shared Prompt 61 dispatcher.
 */
class DispatchDueReportDeliveriesCommand extends Command
{
    protected $signature = 'reports:dispatch-due-deliveries';

    protected $description = 'Dispatch due report delivery schedules via shared recurring automation engine';

    public function handle(RecurringAutomationDispatcher $dispatcher): int
    {
        $ids = $dispatcher->dispatchDue(onlyKinds: [RecurringScheduleKind::ReportDelivery]);
        $this->info('Dispatched occurrences: '.count($ids));

        return self::SUCCESS;
    }
}
