<?php

namespace App\Console\Commands;

use App\Services\Catalog\ServiceCatalogCleaner;
use Illuminate\Console\Command;

/**
 * moxdop:services:clean — one-time (idempotent) clean-up of the service catalog: junk names archived, duplicates of
 * one sector and language merged. `--dry-run` only reports.
 */
final class ServicesCleanCommand extends Command
{
    protected $signature = 'moxdop:services:clean {--dry-run : Only report what would change}';

    protected $description = 'Archive junk service names and merge same-sector duplicates in the service catalog.';

    public function handle(ServiceCatalogCleaner $cleaner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $cleaner->clean($dryRun);
        $this->info(sprintf('%sHizmet kataloğu: %d hizmet kontrol edildi · %d arşivlendi · %d korundu (operatör / kilitli) · %d grupta %d kopya birleşti · %d sorgu yeniden sıraya döndü.',
            $dryRun ? '[deneme] ' : '', $summary['checked'], $summary['archived'], $summary['protected'], $summary['groups'], $summary['merged'], $summary['released']));
        foreach (array_slice($summary['archived_names'], 0, 50) as $name) {
            $this->line('  arşiv: '.$name);
        }
        foreach (array_slice($summary['merges'], 0, 50) as $merge) {
            $this->line('  birleşti: '.implode(', ', $merge['from']).' → '.$merge['into']);
        }

        return self::SUCCESS;
    }
}
