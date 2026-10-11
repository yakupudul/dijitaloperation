<?php

namespace App\Console\Commands;

use App\Jobs\Queries\FinishQueriesJob;
use App\Services\Queries\QueryFinish;
use Illuminate\Console\Command;

/**
 * moxdop:queries:finish — queues the chain that finishes the query library (FinishQueriesJob) and returns at once.
 */
final class QueriesFinishCommand extends Command
{
    protected $signature = 'moxdop:queries:finish';

    protected $description = 'Queue the query library finish chain: catalog clean-up, triage again, clustering, content titles, check.';

    public function handle(): int
    {
        QueryFinish::record(['stage' => 'queued', 'started_at' => now()->toIso8601String(), 'error' => null]);
        FinishQueriesJob::dispatch('clean');
        $this->info('Sorgu kütüphanesini bitirme zinciri kuyruğa alındı (temizlik → yeniden triage → kümeleme → içerik başlıkları → kontrol).');

        return self::SUCCESS;
    }
}
