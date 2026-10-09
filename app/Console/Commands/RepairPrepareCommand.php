<?php

namespace App\Console\Commands;

use App\Services\Gbp\Desk\ProfileInfo;
use App\Services\Repair\RepairPreparer;
use Illuminate\Console\Command;

/**
 * moxdop:repair:prepare — nightly "Hazırla" of the Onarım masası: website suggestions without a prepared value are
 * queued for "AI ile yap" so they arrive ready to approve; Business Profile facts that the brand's own data can fill
 * (hours, website and appointment links) are prepared as profile suggestions (ADR-080).
 */
final class RepairPrepareCommand extends Command
{
    protected $signature = 'moxdop:repair:prepare {--fields= : Field fixes to queue} {--content= : Page-text fixes to queue} {--batch= : Title / description pages to queue (15 per AI call)} {--batch-only : Only the title / description batches (hourly)}';

    protected $description = 'Queue the preparation of website fixes for the repair desk.';

    public function handle(RepairPreparer $preparer, ProfileInfo $profiles): int
    {
        $fields = $this->option('fields');
        $content = $this->option('content');
        $batch = $this->option('batch');
        $queued = $preparer->queue($fields !== null ? (int) $fields : null, $content !== null ? (int) $content : null, $batch !== null ? (int) $batch : null, (bool) $this->option('batch-only'));
        $this->line(sprintf('%d sayfanın başlık / açıklaması toplu, %d alan düzeltmesi ve %d sayfa metni düzeltmesi hazırlanmak üzere kuyruğa alındı.', $queued['batch'], $queued['fields'], $queued['content']));
        if ($this->option('batch-only')) {
            return self::SUCCESS;
        }
        $gbp = $profiles->prepareAll();
        $this->line(sprintf('%d İşletme Profili bilgisi %d profilde hazırlandı.', $gbp['prepared'], $gbp['profiles']));

        return self::SUCCESS;
    }
}
