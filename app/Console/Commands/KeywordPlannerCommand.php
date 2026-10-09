<?php

namespace App\Console\Commands;

use App\Jobs\Queries\ProcessQueriesJob;
use App\Services\Queries\KeywordPlanner;
use Illuminate\Console\Command;

/**
 * moxdop:queries:keyword-planner — search demand from the Google Ads Anahtar Kelime Planlayıcı (read only) for every
 * operational brand not asked in 30 days (or one brand with --brand, --force to ask again now); the ideas enter the
 * query library like any other query.
 */
final class KeywordPlannerCommand extends Command
{
    protected $signature = 'moxdop:queries:keyword-planner {--brand= : Only this brand id} {--force : Ask again even if asked in the last 30 days}';

    protected $description = 'Read search demand from the Google Ads Keyword Planner into the query library (read only).';

    public function handle(KeywordPlanner $planner): int
    {
        $brand = $this->option('brand');
        $stats = $planner->run(is_numeric($brand) ? (int) $brand : null, (bool) $this->option('force'));
        if ($stats['kept'] > 0) {
            ProcessQueriesJob::dispatch();
        }
        $skipped = collect($stats['skipped'])->map(fn (int $n, string $why): string => $why.' '.$n)->implode(', ');
        $this->info(sprintf('%d marka soruldu, %d fikir geldi, %d arama kütüphaneye eklendi.%s', $stats['brands'], $stats['ideas'], $stats['kept'], $skipped !== '' ? ' Atlanan: '.$skipped : ''));

        return self::SUCCESS;
    }
}
