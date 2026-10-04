<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ClusterPageShares;
use Illuminate\Console\Command;
use Throwable;

/**
 * Küme çakışmaları: rebuilds the overlap work from the stored match (no AI, no site call) — the same step that ends an
 * Eşleştir run. Run once after a change to the overlap rules so old duplicates close without waiting for the next run.
 */
final class ClustersSyncOverlapsCommand extends Command
{
    protected $signature = 'moxdop:clusters:sync-overlaps {--brand= : Only this brand id}';

    protected $description = 'Rebuild the cluster overlap suggestions of every website from the stored cluster match (no AI)';

    public function handle(ClusterOverlaps $overlaps, ClusterPageShares $shares): int
    {
        $pairs = BrandClusterPage::query()->when($this->option('brand'), fn ($q, $id) => $q->where('brand_id', (int) $id))
            ->select(['brand_id', 'website_asset_id'])->distinct()->orderBy('brand_id')->orderBy('website_asset_id')->get();
        $open = 0;
        foreach ($pairs as $pair) {
            $brand = Brand::query()->operational()->find((int) $pair->brand_id);
            $site = DigitalAsset::query()->find((int) $pair->website_asset_id);
            if ($brand === null || $site === null) {
                continue;
            }
            try {
                $clusterIds = BrandClusterPage::query()->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
                    ->distinct()->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all();
                $open += $overlaps->sync($site, $brand, $shares->forClusters($brand, $site, $clusterIds));
            } catch (Throwable $error) {
                report($error);
                $this->warn('Site '.$site->id.': '.$error->getMessage());
            }
        }
        $this->info($open.' açık küme çakışması.');

        return self::SUCCESS;
    }
}
