<?php

namespace App\Jobs\Finance;

use App\Services\Finance\GmailInvoices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Şimdi çek" on Finans › Faturalar: a few pages of the mailbox are read in the background. */
final class FinanceGmailSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 170;

    public function __construct(public int $rounds = 2)
    {
        $this->onQueue('default');
    }

    public function handle(GmailInvoices $gmail): void
    {
        $until = microtime(true) + 100;
        for ($i = 0; $i < $this->rounds && microtime(true) < $until; $i++) {
            $result = $gmail->sync();
            if (! $result['ok'] || ! $result['more'] || $result['error'] !== null) {
                break;
            }
        }
    }
}
