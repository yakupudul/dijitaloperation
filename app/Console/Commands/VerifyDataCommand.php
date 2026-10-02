<?php

namespace App\Console\Commands;

use App\Jobs\Verification\RunDataConsistencyCheckJob;
use App\Services\Verification\DataConsistencyChecker;
use Illuminate\Console\Command;

/** moxdop:verify:data — flags suspicious collected data (missing days, broken tagging, divergence, currency). */
final class VerifyDataCommand extends Command
{
    protected $signature = 'moxdop:verify:data {--sync : Run now in this process instead of queueing}';

    protected $description = 'Check collected GA4 / Search Console / Google Ads / Meta data for gaps and contradictions ("Veri şüpheli" in Komuta merkezi).';

    public function handle(DataConsistencyChecker $checker): int
    {
        if (! $this->option('sync')) {
            RunDataConsistencyCheckJob::dispatch();
            $this->info('Data consistency check queued.');

            return self::SUCCESS;
        }
        $stats = $checker->run();
        $this->info(sprintf('Data consistency: %d open, %d new, %d resolved.', $stats['open'], $stats['new'], $stats['resolved']));

        return self::SUCCESS;
    }
}
