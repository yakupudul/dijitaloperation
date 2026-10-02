<?php

namespace App\Console\Commands;

use App\Jobs\Queries\ProcessQueriesJob;
use App\Services\Queries\QueryPipeline;
use Illuminate\Console\Command;

/**
 * moxdop:queries:process — runs the Sorgular pipeline (query_sources → queries → brand_queries) now, or queues it.
 */
final class QueriesProcessCommand extends Command
{
    protected $signature = 'moxdop:queries:process {--queue : Kuyruğa at (heavy)}';

    protected $description = 'Sorgu kaynaklarını kütüphaneye bağlar, toplamları ve bekleyen sorguları günceller, marka sorgularını yeniler.';

    public function handle(QueryPipeline $pipeline): int
    {
        if ($this->option('queue')) {
            ProcessQueriesJob::dispatch();
            $this->info('Sorgu hattı kuyruğa alındı.');

            return self::SUCCESS;
        }
        $stats = $pipeline->run();
        $this->info(sprintf('%d kaynak satırı · %d sorgu (%d hizmetli) · %d bekleyen · %d marka sorgusu.',
            $stats['sources'], $stats['queries'], $stats['assigned'], $stats['pending'], $stats['brand_queries']));

        return self::SUCCESS;
    }
}
