<?php

namespace App\Console\Commands;

use App\Jobs\Queries\FillQueryServiceGapsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * moxdop:queries:fill-gaps — one FillQueryServiceGapsJob per sector holding queries AI found no service for (yakup,
 * 2026-10-14 "Ekle, yeniden ata"); returns at once.
 */
final class QueriesFillGapsCommand extends Command
{
    protected $signature = 'moxdop:queries:fill-gaps';

    protected $description = 'Add the services missing for unassigned queries (per sector, via AI) and send those queries back to triage.';

    public function handle(): int
    {
        $sectorIds = DB::table('queries')->whereNull('service_id')->where('hidden', false)->where('is_suggested', false)
            ->whereNotNull('sector_id')->whereNotNull('ai_checked_at')->distinct()->pluck('sector_id')->map(fn ($id): int => (int) $id)->sort()->values();
        foreach ($sectorIds as $sectorId) {
            FillQueryServiceGapsJob::dispatch($sectorId);
        }
        $this->info('Eksik hizmet işi kuyruğa alındı: '.$sectorIds->count().' sektör.');

        return self::SUCCESS;
    }
}
