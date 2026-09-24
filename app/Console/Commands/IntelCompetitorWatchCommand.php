<?php

namespace App\Console\Commands;

use App\Services\Intel\CompetitorSiteWatch;
use Illuminate\Console\Command;

/**
 * moxdop:intel:competitors — weekly public snapshot of approved competitors' sites (new pages, changed message).
 */
final class IntelCompetitorWatchCommand extends Command
{
    protected $signature = 'moxdop:intel:competitors';

    protected $description = 'Snapshot approved competitors\' home page and sitemap and compare with last week.';

    public function handle(CompetitorSiteWatch $watch): int
    {
        $stats = $watch->runDue();
        $this->info(sprintf('%d rakip sitesi okundu, %d tanesinde değişiklik var.', $stats['watched'], $stats['changed']));

        return self::SUCCESS;
    }
}
