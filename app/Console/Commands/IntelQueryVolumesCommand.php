<?php

namespace App\Console\Commands;

use App\Services\Intel\QueryVolumes;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:intel:query-volumes — monthly DataForSEO search volume for cluster main queries and brand target queries.
 */
final class IntelQueryVolumesCommand extends Command
{
    protected $signature = 'moxdop:intel:query-volumes';

    protected $description = 'Refresh DataForSEO search volume for cluster main queries and brand target queries (monthly).';

    public function handle(QueryVolumes $volumes): int
    {
        try {
            $stats = $volumes->refreshPlanned();
        } catch (Throwable $error) {
            report($error);
            $this->error('Arama hacmi alınamadı: '.$error->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('Ana sorgu %d, hedef sorgu %d.', $stats['main_queries'], $stats['target_queries']));

        return self::SUCCESS;
    }
}
