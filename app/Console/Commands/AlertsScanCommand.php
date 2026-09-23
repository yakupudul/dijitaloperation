<?php

namespace App\Console\Commands;

use App\Services\Alerts\AssetAlertScanner;
use Illuminate\Console\Command;

/**
 * moxdop:alerts:scan — daily asset alerts from collected data (spend spike/stop, conversions stopped,
 * search traffic drop, stale data, low-rated unanswered reviews).
 */
final class AlertsScanCommand extends Command
{
    protected $signature = 'moxdop:alerts:scan';

    protected $description = 'Detect and resolve daily asset alerts from collected data.';

    public function handle(AssetAlertScanner $scanner): int
    {
        if (! config('moxdop-alerts.enabled', true)) {
            $this->info('Uyarılar kapalı (MOXDOP_ALERTS_ENABLED=false).');

            return self::SUCCESS;
        }
        $totals = $scanner->scanAll();
        $this->info(sprintf('%d varlık tarandı: %d açık uyarı (%d yeni), %d çözüldü.', $totals['assets'], $totals['open'], $totals['new'], $totals['resolved']));

        return self::SUCCESS;
    }
}
