<?php

namespace App\Services\Site\Competitors;

use App\Models\BrandClusterSerp;
use App\Models\DigitalAsset;
use App\Services\Intel\SerpResults;
use App\Services\Site\Backlinks\SerpMentionSources;
use App\Services\Site\SiteDomains;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Rakipleri güncelle" for one website: every approved brand cluster's representative query → cached top-10
 * (SerpResults: 30-day cache, spend guard) → each domain classified → `brand_cluster_serps` (screen table) → the
 * top commercial / information competitors' pages fetched into `competitor_pages`. Operational brands only.
 */
final class CompetitorRefresher
{
    public const array PAGE_CLASSES = ['ticari', 'bilgi'];

    public function __construct(
        private readonly CompetitorTargets $targets,
        private readonly SerpResults $serp,
        private readonly CompetitorClassifier $classifier,
        private readonly CompetitorPageStore $pages,
    ) {}

    public static function statusKey(int $siteId): string
    {
        return 'site:competitors:refresh:'.$siteId;
    }

    public static function markRunning(int $siteId): void
    {
        Cache::put(self::statusKey($siteId), ['status' => 'running'], now()->addDay());
    }

    /** @return array{status: string, clusters: int, serps: int, errors: int, pages: int} status: ready | not_operational | no_clusters */
    public function refresh(DigitalAsset $site): array
    {
        $stats = ['status' => 'ready', 'clusters' => 0, 'serps' => 0, 'errors' => 0, 'pages' => 0];
        $brand = $site->brand;
        if ($brand === null || ! $brand->isOperational() || ! $site->isOperational()) {
            return ['status' => 'not_operational'] + $stats;
        }
        $targets = $this->targets->forSite($site);
        $stats['clusters'] = count($targets);
        BrandClusterSerp::query()->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->whereNotIn('cluster_id', array_map(fn (array $t): int => (int) $t['cluster']->id, $targets) ?: [0])->delete();
        if ($targets === []) {
            return ['status' => 'no_clusters'] + $stats;
        }

        $serps = [];
        $domains = [];
        foreach ($targets as $i => $target) {
            try {
                $top = $this->serp->topTen($target['query'], $target['location_code'], $target['language_code'], $target['device'], (int) $brand->id);
                $organic = array_values(array_filter($top['results'], fn (array $r): bool => $r['type'] === 'organic'));
                $serps[$i] = ['results' => $organic, 'fetched_at' => $top['fetched_at'], 'error' => null];
                foreach ($organic as $result) {
                    $host = SiteDomains::host($result['url']) ?? SiteDomains::host($result['domain']);
                    if ($host !== null) {
                        $domains[$host][] = ['url' => $result['url'], 'title' => $result['title']];
                    }
                }
                $stats['serps']++;
            } catch (Throwable $error) {
                Log::warning('site.competitors.serp_failed', ['site' => $site->id, 'query' => $target['query'], 'error' => $error->getMessage()]);
                $serps[$i] = ['results' => null, 'fetched_at' => null, 'error' => mb_substr($error->getMessage(), 0, 500)];
                $stats['errors']++;
            }
        }

        $classes = $domains === [] ? [] : $this->classifier->classify($domains, SiteDomains::ownDomains($brand), $brand->sectorCategory?->name);
        foreach ($targets as $i => $target) {
            $values = [
                'query' => mb_substr($target['query'], 0, 500), 'location_code' => $target['location_code'],
                'language_code' => $target['language_code'], 'device' => $target['device'],
            ];
            $row = BrandClusterSerp::query()->firstOrNew(['brand_id' => $brand->id, 'cluster_id' => $target['cluster']->id, 'website_asset_id' => $site->id]);
            if ($serps[$i]['error'] !== null) {
                // Keep the last good top-10; only the error is shown.
                $row->fill($values + ['status' => 'error', 'error' => $serps[$i]['error']])->save();

                continue;
            }
            $results = [];
            $ownRank = null;
            foreach ($serps[$i]['results'] as $result) {
                $host = SiteDomains::host($result['url']) ?? SiteDomains::host($result['domain']) ?? (string) $result['domain'];
                $class = $classes[$host] ?? null;
                if ($class === CompetitorClassifier::OWN && $ownRank === null) {
                    $ownRank = (int) $result['rank'];
                }
                $results[] = ['rank' => (int) $result['rank'], 'url' => $result['url'], 'domain' => $host, 'title' => $result['title'], 'class' => $class];
            }
            $row->fill($values + ['status' => 'ready', 'error' => null, 'results' => $results, 'own_rank' => $ownRank, 'fetched_at' => $serps[$i]['fetched_at']])->save();

            $wanted = array_slice(array_values(array_filter($results, fn (array $r): bool => in_array($r['class'], self::PAGE_CLASSES, true))), 0, (int) config('moxdop-site.competitors.pages_per_cluster', 5));
            foreach ($wanted as $result) {
                $this->pages->ensure($result['url'], $result['domain'], $result['class']);
                $stats['pages']++;
            }
        }
        try {
            app(SerpMentionSources::class)->sync($brand);
        } catch (Throwable $error) {
            Log::warning('site.mentions.sync_failed', ['brand' => $brand->id, 'error' => $error->getMessage()]);
        }

        return $stats;
    }
}
