<?php

namespace App\Console\Commands;

use App\Services\Brand\BrandSiteLogo;
use Illuminate\Console\Command;

/**
 * moxdop:brands:site-logos — copy each brand's logo from its own website (operator-typed logos are kept).
 */
final class BrandSiteLogosCommand extends Command
{
    protected $signature = 'moxdop:brands:site-logos';

    protected $description = 'Take each brand\'s logo from its website when the operator has not set one.';

    public function handle(BrandSiteLogo $logos): int
    {
        $totals = $logos->refreshAll();
        $this->info(sprintf('%d marka: %d logo siteden alındı, %d elle girilmiş korundu, %d bulunamadı.', $totals['brands'], $totals['saved'], $totals['kept'], $totals['missing']));

        return self::SUCCESS;
    }
}
