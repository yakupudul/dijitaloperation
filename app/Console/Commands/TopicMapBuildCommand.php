<?php

namespace App\Console\Commands;

use App\Models\DigitalAsset;
use App\Services\ContentStudio\TopicMapBuilder;
use App\Support\ServiceScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:topics:build — weekly topic map (Faz 3) of every operational website whose brand has hub queries: stored
 * data only, no provider calls, no AI. Runs after the query hub rebuild.
 */
final class TopicMapBuildCommand extends Command
{
    protected $signature = 'moxdop:topics:build {--site= : Only this website asset id} {--queue : Queue the builds instead of running them here}';

    protected $description = 'Rebuild the topic map (hub queries → service → topic clusters → owner page, coverage, verdict) of operational websites.';

    public function handle(TopicMapBuilder $builder, ServiceScope $scope): int
    {
        $sites = DigitalAsset::query()->where('type', 'website')->whereIn('id', $scope->operationalAssetIds())
            ->whereIn('brand_id', fn ($q) => $q->from('brand_demand_queries')->select('brand_id'))
            ->when($this->option('site'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')->get();
        foreach ($sites as $site) {
            try {
                if ($this->option('queue')) {
                    $builder->queue($site, 'weekly');
                    $this->line($site->name.': kuyruğa alındı.');

                    continue;
                }
                $build = $builder->buildRecorded($site, 'weekly');
                $stats = (array) $build->stats;
                $this->line($build->status === 'done'
                    ? sprintf('%s: %d sorgu, %d konu kümesi (sürüm %d).', $site->name, $stats['queries'] ?? 0, $stats['clusters'] ?? 0, $stats['version'] ?? 0)
                    : $site->name.': '.$build->error);
            } catch (Throwable $exception) {
                report($exception);
                $this->error($site->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
