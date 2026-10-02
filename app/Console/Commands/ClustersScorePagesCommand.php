<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Services\Site\ClusterPageScorer;
use Illuminate\Console\Command;
use Throwable;

/** Sayfa puanı: every brand with cluster rows, nightly after the Search Console collections. */
final class ClustersScorePagesCommand extends Command
{
    protected $signature = 'moxdop:clusters:score-pages {--brand= : Only this brand id}';

    protected $description = 'Score the pages assigned to clusters (Search Console, last 90 days) for every brand';

    public function handle(ClusterPageScorer $scorer): int
    {
        $ids = BrandClusterPage::query()->when($this->option('brand'), fn ($q, $id) => $q->where('brand_id', (int) $id))
            ->distinct()->pluck('brand_id');
        $rows = 0;
        foreach (Brand::query()->whereIn('id', $ids)->orderBy('id')->get() as $brand) {
            try {
                $rows += $scorer->scoreBrand($brand);
            } catch (Throwable $error) {
                report($error);
                $this->warn('Marka '.$brand->id.': '.$error->getMessage());
            }
        }
        $this->info($rows.' küme sayfası puanlandı.');

        return self::SUCCESS;
    }
}
