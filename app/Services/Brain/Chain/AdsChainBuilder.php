<?php

namespace App\Services\Brain\Chain;

use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Brain\Clustering\ServiceClusters;
use App\Services\Brain\RecommendationWriter;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Google Ads side of the service chain: which topic cluster each ad group serves (from its keywords and the search
 * terms it actually matched, weighted by impressions), where it sends people, and whether that is the page that owns
 * the cluster on the brand's site. The Quality Score logic behind it: one cluster → one ad group → one page.
 *
 * Recommendations (stored data, no AI):
 *  - ads_split_ad_group: an ad group serves two or more clusters (each ≥ SPLIT_SHARE of its impressions)
 *  - ads_final_url: its final URL is not the page that owns its cluster on the brand's site
 *  - ads_cross_cluster_terms: it pays for search terms of a cluster another ad group owns (negatives route them)
 *  - ads_missing_cluster: a buying cluster of an advertised service has organic demand but no ad group
 */
final class AdsChainBuilder
{
    public const float SPLIT_SHARE = 0.25;

    private const float MIN_CROSS_COST = 1.0;

    public function __construct(
        private readonly GoogleAdsAdvisorInputCollector $collector,
        private readonly ServiceClusters $clusters,
        private readonly RecommendationWriter $writer,
    ) {}

    /** @return int ad groups stored */
    public function build(DigitalAsset $asset): int
    {
        try {
            $data = $this->collector->collect($asset);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
        if (! ($data['bound'] ?? false)) {
            return 0;
        }

        return $this->store($asset, $data);
    }

    /**
     * @param  array<string, mixed>  $data  GoogleAdsAdvisorInputCollector output
     */
    public function store(DigitalAsset $asset, array $data): int
    {
        $serviceIds = DB::table('brand_offerings')->where('brand_id', $asset->brand_id)->where('status', 'active')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id')->map('intval')->all();
        $clusters = $this->clusters->all($serviceIds);
        $keyCluster = [];
        foreach ($clusters as $cluster) {
            foreach (array_keys($cluster['keys']) as $key) {
                $keyCluster[$key] ??= $cluster['id'];
            }
        }
        $site = DigitalAsset::query()->operational()->where('type', 'website')->where('brand_id', $asset->brand_id)->orderBy('id')->first();
        $targets = $site === null ? collect() : DB::table('library_cluster_targets')->where('digital_asset_id', $site->id)->where('url', '!=', '')->pluck('url', 'cluster_key');

        $groups = $this->adGroups($data, $keyCluster);
        $rows = [];
        $owner = [];
        foreach ($groups as $id => $group) {
            arsort($group['mix']);
            $total = array_sum($group['mix']);
            $primary = $total > 0 ? (int) array_key_first($group['mix']) : null;
            $share = $primary !== null ? $group['mix'][$primary] / $total : null;
            if ($primary !== null && $share >= 0.5) {
                $owner[$primary] ??= $id;
            }
            $target = $primary !== null ? ($targets[$primary] ?? null) : null;
            $finalUrl = $group['final_url'];
            $rows[$id] = [
                'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'campaign_id' => $group['campaign_id'], 'ad_group_id' => (string) $id,
                'ad_group_name' => mb_substr($group['name'], 0, 500), 'service_id' => $primary !== null ? $clusters[$primary]['service_id'] : null,
                'cluster_id' => $primary, 'cluster_share' => $share !== null ? round($share, 4) : null,
                'cluster_mix' => json_encode(array_map(fn (float $v): float => round($v / max(1, $total), 3), $group['mix'])),
                'final_url' => $finalUrl, 'target_url' => $target,
                'url_matches' => $target !== null && $finalUrl !== null ? SeoText::urlKey($finalUrl) === SeoText::urlKey((string) $target) : null,
                'quality_score' => $group['qs'], 'impressions' => $group['impressions'], 'clicks' => $group['clicks'],
                'conversions' => round($group['conversions'], 2), 'cost' => round($group['cost'], 2),
                'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::transaction(function () use ($asset, $rows): void {
            DB::table('brain_ad_group_clusters')->where('digital_asset_id', $asset->id)->delete();
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                DB::table('brain_ad_group_clusters')->insert($chunk);
            }
        });

        $this->writer->sync(['source' => 'ads_chain', 'digital_asset_id' => (int) $asset->id], $this->recommendations($asset, $groups, $rows, $clusters, $owner, $site?->id, $serviceIds));

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $keyCluster
     * @return array<string, array{name: string, campaign_id: ?string, mix: array<int, float>, terms: array<string, array{cluster: ?int, cost: float, impressions: int}>, final_url: ?string, qs: ?float, impressions: int, clicks: int, conversions: float, cost: float}>
     */
    private function adGroups(array $data, array $keyCluster): array
    {
        $names = (array) ($data['ads']['ad_groups'] ?? []);
        $groups = [];
        $new = fn (string $id, ?string $campaign): array => ['name' => (string) ($names[$id] ?? 'Reklam grubu '.$id), 'campaign_id' => $campaign, 'mix' => [], 'terms' => [],
            'final_url' => null, 'qs' => null, 'qs_sum' => 0.0, 'qs_n' => 0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0.0, 'cost' => 0.0, 'urls' => []];
        foreach ((array) ($data['keywords'] ?? []) as $keyword) {
            $id = (string) ($keyword['ad_group_id'] ?? '');
            if ($id === '' || ($keyword['status'] ?? null) === 'REMOVED') {
                continue;
            }
            $groups[$id] ??= $new($id, $keyword['campaign_id'] ?? null);
            $cluster = $keyCluster[ServiceClusters::key((string) $keyword['text'])] ?? null;
            if ($cluster !== null) {
                $groups[$id]['mix'][$cluster] = ($groups[$id]['mix'][$cluster] ?? 0) + max(1, (int) $keyword['impressions']);
            }
            $groups[$id]['impressions'] += (int) $keyword['impressions'];
            $groups[$id]['clicks'] += (int) $keyword['clicks'];
            $groups[$id]['conversions'] += (float) $keyword['conversions'];
            $groups[$id]['cost'] += (float) $keyword['cost'];
            if ($keyword['quality_score'] !== null && (int) $keyword['impressions'] > 0) {
                $groups[$id]['qs_sum'] += $keyword['quality_score'] * (int) $keyword['impressions'];
                $groups[$id]['qs_n'] += (int) $keyword['impressions'];
            }
        }
        foreach ((array) ($data['search_terms'] ?? []) as $term) {
            $adGroupIds = (array) ($term['ad_group_ids'] ?? []);
            if (count($adGroupIds) !== 1 || ! empty($term['pmax'])) {
                continue;
            }
            $id = (string) $adGroupIds[0];
            $groups[$id] ??= $new($id, $term['campaign_ids'][0] ?? null);
            $cluster = $keyCluster[ServiceClusters::key((string) $term['term'])] ?? null;
            if ($cluster !== null) {
                $groups[$id]['mix'][$cluster] = ($groups[$id]['mix'][$cluster] ?? 0) + max(1, (int) $term['impressions']);
            }
            $groups[$id]['terms'][(string) $term['term']] = ['cluster' => $cluster, 'cost' => (float) $term['cost'], 'impressions' => (int) $term['impressions']];
        }
        foreach ((array) ($data['ads']['items'] ?? []) as $ad) {
            $id = (string) ($ad['ad_group_id'] ?? '');
            if (! isset($groups[$id]) || ($ad['status'] ?? null) === 'REMOVED') {
                continue;
            }
            foreach ((array) $ad['final_urls'] as $url) {
                $groups[$id]['urls'][$url] = ($groups[$id]['urls'][$url] ?? 0) + 1;
            }
        }
        foreach ($groups as &$group) {
            arsort($group['urls']);
            $group['final_url'] = array_key_first($group['urls']);
            $group['qs'] = $group['qs_n'] > 0 ? round($group['qs_sum'] / $group['qs_n'], 2) : null;
            unset($group['urls'], $group['qs_sum'], $group['qs_n']);
        }

        return $groups;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $clusters
     * @param  array<int, string>  $owner  cluster id => ad group id that mostly serves it
     * @param  list<int>  $serviceIds
     * @return list<array<string, mixed>>
     */
    private function recommendations(DigitalAsset $asset, array $groups, array $rows, array $clusters, array $owner, ?int $siteId, array $serviceIds): array
    {
        $out = [];
        foreach ($groups as $id => $group) {
            $row = $rows[$id];
            $total = array_sum($group['mix']);
            $big = array_filter($group['mix'], fn (float $v): bool => $total > 0 && $v / $total >= self::SPLIT_SHARE);
            if (count($big) >= 2) {
                $names = array_map(fn (int $c): string => $clusters[$c]['name'], array_keys($big));
                $out[] = ['key' => $id, 'channel' => 'google_ads', 'type' => 'ads_split_ad_group', 'service_id' => $row['service_id'], 'cluster_id' => $row['cluster_id'],
                    'title' => '"'.$group['name'].'" reklam grubunu konulara göre böl: '.implode(' / ', $names),
                    'detail' => 'Bu reklam grubu birden fazla sayfa konusuna hizmet ediyor. Her konu için ayrı reklam grubu ve o konunun sayfasına giden reklam, reklam alaka düzeyini ve açılış sayfası deneyimini (kalite puanı) yükseltir.',
                    'evidence' => ['ad_group_id' => $id, 'mix' => json_decode((string) $row['cluster_mix'], true), 'quality_score' => $row['quality_score']], 'impact' => round($group['cost'], 2)];
            }
            if ($row['url_matches'] === false) {
                $out[] = ['key' => $id, 'channel' => 'google_ads', 'type' => 'ads_final_url', 'service_id' => $row['service_id'], 'cluster_id' => $row['cluster_id'],
                    'title' => '"'.$group['name'].'" reklamlarını "'.$clusters[$row['cluster_id']]['name'].'" sayfasına yönlendir',
                    'detail' => 'Reklamlar '.$row['final_url'].' adresine gidiyor; bu konunun sitedeki sahibi '.$row['target_url'].'. Aramayla aynı konuyu anlatan sayfa, açılış sayfası deneyimini ve dönüşümü artırır.',
                    'evidence' => ['ad_group_id' => $id, 'final_url' => $row['final_url'], 'target_url' => $row['target_url'], 'quality_score' => $row['quality_score']], 'impact' => round($group['cost'], 2)];
            }
            $cross = [];
            foreach ($group['terms'] as $term => $info) {
                if ($info['cluster'] !== null && $info['cluster'] !== $row['cluster_id'] && isset($owner[$info['cluster']]) && $owner[$info['cluster']] !== $id && $info['cost'] >= self::MIN_CROSS_COST) {
                    $cross[$term] = $info['cost'];
                }
            }
            if ($cross !== []) {
                arsort($cross);
                $out[] = ['key' => $id, 'channel' => 'google_ads', 'type' => 'ads_cross_cluster_terms', 'service_id' => $row['service_id'], 'cluster_id' => $row['cluster_id'],
                    'title' => '"'.$group['name'].'" başka reklam grubunun konusuna para ödüyor ('.count($cross).' arama terimi)',
                    'detail' => 'Bu terimler hesapta kendi reklam grubu olan başka bir konuya ait. Bu reklam grubuna tam eşleme negatif olarak eklenirse trafik doğru reklam ve doğru sayfaya gider.',
                    'evidence' => ['ad_group_id' => $id, 'terms' => array_slice(array_map(fn ($c): float => round($c, 2), $cross), 0, 25, true)], 'impact' => round(array_sum($cross), 2)];
            }
        }
        // Buying clusters of services the account already advertises, with no ad group of their own.
        $advertised = array_values(array_unique(array_filter(array_column($rows, 'service_id'))));
        foreach ($clusters as $cluster) {
            if (! in_array($cluster['service_id'], $advertised, true) || ! in_array($cluster['page_type'], ['main', 'landing'], true) || isset($owner[$cluster['id']])) {
                continue;
            }
            $covered = collect($rows)->contains(fn (array $r): bool => isset(json_decode((string) $r['cluster_mix'], true)[$cluster['id']]));
            if ($covered) {
                continue;
            }
            $out[] = ['key' => (string) $cluster['id'], 'channel' => 'google_ads', 'type' => 'ads_missing_cluster', 'service_id' => $cluster['service_id'], 'cluster_id' => $cluster['id'],
                'title' => '"'.$cluster['name'].'" konusu için reklam grubu yok',
                'detail' => 'Bu hizmetin reklamı veriliyor ama bu satın alma konusu için ayrı reklam grubu yok. Konunun sorgularıyla bir reklam grubu açılıp '.($siteId !== null ? 'konunun sayfasına' : 'ilgili sayfaya').' yönlendirilebilir.',
                'evidence' => ['cluster_id' => $cluster['id'], 'queries' => array_slice(array_keys($cluster['keys']), 0, 15)], 'impact' => null];
        }

        return $out;
    }
}
