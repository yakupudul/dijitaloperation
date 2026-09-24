<?php

namespace App\Console\Commands;

use App\Services\Operations\OpsWatchdog;
use Illuminate\Console\Command;

/**
 * moxdop:ops:watchdog — run by its own cron line, NOT by the Laravel scheduler, so it notices a stopped scheduler.
 */
final class OpsWatchdogCommand extends Command
{
    protected $signature = 'moxdop:ops:watchdog';

    protected $description = 'Check scheduler / worker heartbeats and queue backlog from outside the scheduler; push a phone alert on problems.';

    public function handle(OpsWatchdog $watchdog): int
    {
        $problems = $watchdog->run();
        foreach ($problems as $problem) {
            $this->warn($problem);
        }
        if ($problems === []) {
            $this->info('Zamanlayıcı, işçiler ve kuyruk sağlıklı.');
        }

        return self::SUCCESS;
    }
}
