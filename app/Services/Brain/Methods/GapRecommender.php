<?php

namespace App\Services\Brain\Methods;

use App\Services\Brain\Clustering\PageTypes;
use App\Services\Brain\Clustering\ServiceClusters;
use App\Services\Brain\RecommendationWriter;
use App\Services\Brain\ServiceSites;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Turns the Brain's knowledge into recommendations for each brand website (gap = what the method asks − what the
 * page has):
 *  - website_create_page: a main / landing / support cluster has no page on the site
 *  - website_merge_pages: two pages split one cluster (from the cannibalization check)
 *  - website_method:{feature}: the cohort's successful pages have a feature this page lacks (hypothesis or validated
 *    method; retired methods are never recommended)
 *  - website_subtopics: sub-questions of the topic the AI checklist found unanswered on the page
 * Other brands are never named: a method is described by counts only (ADR-066).
 */
final class GapRecommender
{
    public function __construct(
        private readonly ServiceClusters $clusters,
        private readonly ServiceSites $sites,
        private readonly RecommendationWriter $writer,
    ) {}

    /** @return int recommendations added */
    public function run(): int
    {
        $clusters = $this->clusters->all();
        if ($clusters === []) {
            return 0;
        }
        $methods = DB::table('brain_methods')->where('channel', 'website')->whereIn('status', ['hypothesis', 'validated'])->get()
            ->groupBy(fn ($m): string => $m->service_id.'|'.$m->page_type);
        $period = DB::table('brain_success_snapshots')->max('period');
        $added = 0;
        $bySite = [];
        foreach (collect($clusters)->groupBy('service_id') as $serviceId => $serviceClusters) {
            foreach ($this->sites->websites((int) $serviceId) as $site) {
                $bySite[$site->id] ??= [];
                $targets = DB::table('library_cluster_targets')->where('digital_asset_id', $site->id)->where('url', '!=', '')->pluck('url', 'cluster_key');
                $snapshots = $period === null ? collect() : DB::table('brain_success_snapshots')->where('period', $period)->where('digital_asset_id', $site->id)->get()->keyBy('cluster_id');
                $cannibal = DB::table('brain_cannibalizations')->where('digital_asset_id', $site->id)->where('status', 'open')->get()->keyBy('cluster_id');
                foreach ($serviceClusters as $cluster) {
                    if ($cluster['page_type'] === 'faq') {
                        continue;
                    }
                    $snapshot = $snapshots->get($cluster['id']);
                    $url = $targets[$cluster['id']] ?? $snapshot?->url;
                    $base = ['service_id' => (int) $serviceId, 'cluster_id' => $cluster['id'], 'brand_id' => (int) $site->brand_id, 'digital_asset_id' => (int) $site->id, 'channel' => 'website'];
                    if ($url === null) {
                        if ($cluster['queries'] >= (int) config('moxdop-brain.gaps.min_cluster_queries', 3)) {
                            $bySite[$site->id][] = $base + ['key' => (string) $cluster['id'], 'type' => 'website_create_page',
                                'title' => '"'.$cluster['name'].'" için sayfa yok — '.mb_strtolower(PageTypes::label($cluster['page_type'])).' oluştur',
                                'detail' => 'Bu konu '.$cluster['queries'].' sorguyla aranıyor ama sitede onu üstlenen sayfa yok. Tek bir sayfa bu kümenin sorularını cevaplamalı; reklamlar da bu sayfaya yönlendirilmeli.',
                                'evidence' => ['queries' => array_slice(array_keys($cluster['keys']), 0, 20), 'page_type' => $cluster['page_type'], 'intent' => $cluster['intent']]];
                        }

                        continue;
                    }
                    if ($cannibal->has($cluster['id'])) {
                        $pages = (array) json_decode((string) $cannibal[$cluster['id']]->pages, true);
                        $bySite[$site->id][] = $base + ['key' => (string) $cluster['id'], 'type' => 'website_merge_pages',
                            'title' => '"'.$cluster['name'].'" konusunu tek sayfada topla ('.count($pages).' sayfa bölüşüyor)',
                            'detail' => 'Aynı konu için iki sayfa Google\'da birbiriyle yarışıyor. Güçlü olanı ana sayfa yapın, diğerini ona yönlendirin (301) ya da içeriğini farklı bir konuya ayırın.',
                            'evidence' => ['pages' => $pages], 'impact' => (float) $cannibal[$cluster['id']]->impressions];
                    }
                    $row = DB::table('brain_page_features')->where('digital_asset_id', $site->id)->where('url_key', SeoText::urlKey((string) $url))->first();
                    if ($row === null) {
                        continue;
                    }
                    $features = (array) json_decode((string) $row->features, true);
                    $ai = $row->ai_features !== null ? (array) json_decode((string) $row->ai_features, true) : null;
                    foreach ($methods->get($serviceId.'|'.$cluster['page_type'], collect()) as $method) {
                        $value = MethodCatalog::value($method->feature, $features, $ai);
                        $def = MethodCatalog::FEATURES[$method->feature] ?? null;
                        if ($def === null || $value === null) {
                            continue;
                        }
                        $has = $def['kind'] === 'bool' ? (bool) $value : (float) $value >= (float) $method->threshold;
                        if ($has) {
                            continue;
                        }
                        $evidence = (array) json_decode((string) $method->evidence, true);
                        $bySite[$site->id][] = $base + ['key' => $cluster['id'].'|'.$method->feature, 'type' => 'website_method:'.$method->feature,
                            'title' => $cluster['name'].': '.rtrim(MethodCatalog::text($def['advice'], $method->threshold !== null ? (float) $method->threshold : null, $method->feature), '.'),
                            'detail' => sprintf('Bu hizmetin %s sayfalarında başarılı olanların %%%d\'inde var, zayıf olanların %%%d\'inde. (%d marka, %d sayfa karşılaştırıldı.)',
                                mb_strtolower(PageTypes::label($cluster['page_type'])), (int) round(($evidence['top_rate'] ?? 0) * 100), (int) round(($evidence['bottom_rate'] ?? 0) * 100), (int) ($evidence['brands'] ?? 0), (int) ($evidence['pages'] ?? 0)),
                            'evidence' => ['method' => $method->label, 'page' => $url, 'current' => is_bool($value) ? $value : round((float) $value, 3)] + $evidence,
                            'basis' => $method->status === 'validated' ? 'validated' : 'observational', 'method_id' => (int) $method->id,
                            'impact' => $snapshot !== null ? (float) $snapshot->impressions : null];
                    }
                    $missing = (array) ($ai['subtopics_missing'] ?? []);
                    if ($missing !== []) {
                        $bySite[$site->id][] = $base + ['key' => (string) $cluster['id'], 'type' => 'website_subtopics',
                            'title' => $cluster['name'].': sayfada cevaplanmayan '.count($missing).' soru',
                            'detail' => 'Konunun şu soruları sayfada cevaplanmıyor: '.implode(' · ', $missing).'. Her biri için kısa, kendi başına anlaşılır bir cevap bölümü ekleyin.',
                            'evidence' => ['page' => $url, 'questions' => $missing]];
                    }
                }
            }
        }
        foreach ($bySite as $siteId => $items) {
            $added += $this->writer->sync(['source' => 'brain_gaps', 'digital_asset_id' => (int) $siteId], $items)['added'];
        }

        return $added;
    }
}
