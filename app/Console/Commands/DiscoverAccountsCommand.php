<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledDiscoveryJob;
use Illuminate\Console\Command;

/** moxdop:integrations:discover — queues the daily Google / Meta account discovery. */
final class DiscoverAccountsCommand extends Command
{
    protected $signature = 'moxdop:integrations:discover';

    protected $description = 'Discover Google and Meta accounts daily: new accounts and lost access are pushed and listed in Komuta merkezi.';

    public function handle(): int
    {
        RunScheduledDiscoveryJob::dispatch();
        $this->info('Account discovery queued.');

        return self::SUCCESS;
    }
}
