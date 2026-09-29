<?php

namespace App\Console\Commands;

use App\Services\Retention\DataRetentionService;
use Illuminate\Console\Command;

/**
 * moxdop:retention — monthly lean-data job (MoxDOP v2): daily facts 16 months (older rolled into monthly rows, then
 * deleted), query daily facts 16 months (monthly form = query_sources, kept 24 months), raw payloads 90 days (latest
 * HTML kept), telemetry windows. Dry run unless --apply.
 */
final class DataRetentionCommand extends Command
{
    protected $signature = 'moxdop:retention {--apply : Değişiklikleri uygula (varsayılan: yalnız sayar)}';

    protected $description = 'Veri saklama: 16 aydan eski günlük verileri aylığa çevirip siler, sorgu kaynaklarını 24 ay tutar, ham kopyaları ve telemetriyi temizler.';

    public function handle(DataRetentionService $retention): int
    {
        if (! config('moxdop-retention.enabled', true)) {
            $this->info('Veri saklama işi kapalı (MOXDOP_RETENTION_ENABLED=false).');

            return self::SUCCESS;
        }
        $dryRun = ! $this->option('apply');
        $result = $retention->run($dryRun);
        $this->info(sprintf(
            '%sGünlük veri sınırı: %s · ham kopya: %d · telemetri: %d · aylığa çevrilen günlük satır: %d (%d aylık satır) · silinen sorgu günlük satırı: %d · silinen sorgu kaynağı: %d.',
            $dryRun ? '[deneme] ' : '',
            $retention->dailyCutoff()->toDateString(),
            $result['raw_objects'],
            array_sum($result['telemetry']),
            $result['rolled_rows'],
            $result['rollup_rows'],
            array_sum($result['query_daily_rows']),
            $result['query_source_rows'],
        ));
        foreach (array_filter([...$result['telemetry'], ...$result['query_daily_rows']]) as $table => $count) {
            $this->line("  {$table}: {$count}");
        }
        if ($dryRun) {
            $this->line('Uygulamak için: php artisan moxdop:retention --apply');
        }

        return self::SUCCESS;
    }
}
