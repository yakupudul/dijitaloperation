<?php

namespace App\Console\Commands;

use App\Models\CoreExternalResource;
use App\Services\Queries\QuerySourceAggregator;
use Illuminate\Console\Command;

/**
 * moxdop:queries:sources — (re)builds the raw query layer `query_sources` from the collected facts: Search Console
 * query × page, Google Ads search terms, Business Profile keywords; per account × query × month. Idempotent.
 */
final class QuerySourcesCommand extends Command
{
    protected $signature = 'moxdop:queries:sources
        {--resource=* : Hesap (core_external_resources.id); birden çok verilebilir}
        {--all : Tüm sorgu kaynağı hesapları (Search Console, Google Ads, İşletme Profili)}
        {--months=16 : Kaç ay geriye}';

    protected $description = 'Sorgu kaynaklarını (query_sources) toplanmış verilerden aylık olarak yeniden hesaplar.';

    public function handle(QuerySourceAggregator $aggregator): int
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('resource'))));
        if ($ids === [] && ! $this->option('all')) {
            $this->error('--resource=<id> ya da --all verin.');

            return self::INVALID;
        }
        $months = max(1, min(36, (int) $this->option('months')));
        $resources = CoreExternalResource::query()
            ->whereIn('resource_type', array_keys(QuerySourceAggregator::SOURCES))
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')->get();
        if ($resources->isEmpty()) {
            $this->warn('Sorgu kaynağı hesabı bulunamadı.');

            return $ids === [] ? self::SUCCESS : self::FAILURE;
        }
        $total = 0;
        foreach ($resources as $resource) {
            $range = $aggregator->factRange($resource, $months);
            if ($range === null) {
                $this->line(sprintf('#%d %s (%s): veri yok', $resource->id, $resource->display_name, $resource->resource_type));

                continue;
            }
            $stats = $aggregator->aggregate($resource, $range[0], $range[1]);
            $total += $stats['rows'];
            $this->line(sprintf('#%d %s (%s): %d ay · %d satır (%s → %s)', $resource->id, $resource->display_name, $resource->resource_type,
                $stats['months'], $stats['rows'], $range[0]->format('Y-m'), $range[1]->format('Y-m')));
        }
        $this->info(sprintf('Toplam %d hesap · %d sorgu × ay satırı.', $resources->count(), $total));

        return self::SUCCESS;
    }
}
