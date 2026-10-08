<?php

namespace App\Console\Commands;

use App\Services\Repair\RepairPreparer;
use Illuminate\Console\Command;

/**
 * moxdop:repair:prepare — nightly "Hazırla" of the Onarım masası: website suggestions without a prepared value are
 * queued for "AI ile yap" so they arrive ready to approve.
 */
final class RepairPrepareCommand extends Command
{
    protected $signature = 'moxdop:repair:prepare {--fields= : Field fixes to queue} {--content= : Page-text fixes to queue}';

    protected $description = 'Queue the preparation of website fixes for the repair desk.';

    public function handle(RepairPreparer $preparer): int
    {
        $fields = $this->option('fields');
        $content = $this->option('content');
        $queued = $preparer->queue($fields !== null ? (int) $fields : null, $content !== null ? (int) $content : null);
        $this->line(sprintf('%d alan düzeltmesi ve %d sayfa metni düzeltmesi hazırlanmak üzere kuyruğa alındı.', $queued['fields'], $queued['content']));

        return self::SUCCESS;
    }
}
