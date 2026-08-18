<?php

namespace App\Console\Commands;

use App\Services\RecurringAutomation\RecurringAutomationDispatcher;
use Illuminate\Console\Command;

class DispatchDueAutomationsCommand extends Command
{
    protected $signature = 'moxdop:dispatch-due-automations
                            {--kind=* : Optional schedule kinds}';

    protected $description = 'Dispatch due shared recurring automation occurrences (Prompt 61)';

    public function handle(RecurringAutomationDispatcher $dispatcher): int
    {
        /** @var list<string> $kinds */
        $kinds = array_values(array_filter(
            array_map(static fn (mixed $v): string => (string) $v, (array) $this->option('kind')),
            static fn (string $v): bool => $v !== '',
        ));

        $ids = $dispatcher->dispatchDue(
            onlyKinds: $kinds === [] ? null : $kinds,
        );

        $this->info('Dispatched occurrences: '.count($ids));

        return self::SUCCESS;
    }
}
