<?php

namespace App\Console\Commands;

use App\Services\Integrations\ResourceAutomationService;
use Illuminate\Console\Command;

/**
 * moxdop:resources:retry-stopped — daily second chance for collections stopped by repeated failures, and for
 * "reconnect" stops whose integration and account are usable again.
 */
final class RetryStoppedCollectionsCommand extends Command
{
    protected $signature = 'moxdop:resources:retry-stopped';

    protected $description = 'Make stopped account collections due again when a retry can succeed.';

    public function handle(ResourceAutomationService $automations): int
    {
        $stats = $automations->retryStopped();
        $this->line(sprintf('%d tekrar eden hatadan, %d yeniden bağlanmış hesaptan toplama yeniden planlandı.', $stats['retried'], $stats['reconnected']));

        return self::SUCCESS;
    }
}
