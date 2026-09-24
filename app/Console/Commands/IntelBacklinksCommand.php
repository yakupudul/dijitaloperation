<?php

namespace App\Console\Commands;

use App\Services\Intel\BacklinkEngine;
use App\Services\Intel\BacklinkLinkChecker;
use Illuminate\Console\Command;

/**
 * moxdop:intel:backlinks — refresh due backlink data (opt-in brands, cap) and check promised links.
 */
final class IntelBacklinksCommand extends Command
{
    protected $signature = 'moxdop:intel:backlinks';

    protected $description = 'Refresh backlink opportunities for opted-in brands and check whether promised links are live.';

    public function handle(BacklinkEngine $engine, BacklinkLinkChecker $checker): int
    {
        $refresh = $engine->runDue();
        $check = $checker->runDue();
        $this->info(sprintf('Yenilenen %d marka (atlanan %d); %d link kontrol edildi, %d yayında, %d kaybedildi.', $refresh['refreshed'], $refresh['skipped'], $check['checked'], $check['live'], $check['lost']));

        return self::SUCCESS;
    }
}
