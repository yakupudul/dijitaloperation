<?php

namespace App\Console\Commands;

use App\Services\Retention\DataRetentionService;
use Illuminate\Console\Command;

/**
 * moxdop:data:retention — daily lean-data job: raw payloads (90 days, latest HTML kept), telemetry windows,
 * and daily performance older than 25 months rolled into monthly rows. Gold data is never touched.
 */
final class DataRetentionCommand extends Command
{
    protected $signature = 'moxdop:data:retention {--dry-run : Only count what would be removed or rolled up}';

    protected $description = 'Trim raw payloads and telemetry, roll old daily performance into monthly rows.';

    public function handle(DataRetentionService $retention): int
    {
        if (! config('moxdop-retention.enabled', true)) {
            $this->info('Veri saklama işi kapalı (MOXDOP_RETENTION_ENABLED=false).');

            return self::SUCCESS;
        }
        $dryRun = (bool) $this->option('dry-run');
        $result = $retention->run($dryRun);
        $this->info(sprintf(
            '%sHam kopya: %d · telemetri: %d · aylığa çevrilen günlük satır: %d (%d aylık satır).',
            $dryRun ? '[deneme] ' : '',
            $result['raw_objects'],
            array_sum($result['telemetry']),
            $result['rolled_rows'],
            $result['rollup_rows'],
        ));
        foreach (array_filter($result['telemetry']) as $table => $count) {
            $this->line("  {$table}: {$count}");
        }

        return self::SUCCESS;
    }
}
