<?php

namespace App\Services\Brain;

use App\Models\ServiceCatalogItem;
use App\Services\Brain\Clustering\ServiceClusters;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Read side of "Hizmet haritası": for one service, its page-sized clusters and, per brand website offering it, which
 * URL owns each cluster, where two URLs split one cluster, and (later phases) ad groups, Meta ads, success and methods.
 */
final class ServiceMapReader
{
    public function __construct(
        private readonly ServiceClusters $clusters,
        private readonly ServiceSites $sites,
    ) {}

    /** @return list<array{id: int, name: string, sector: string, clusters: int, brands: int}> */
    public function services(): array
    {
        $clusterCounts = DB::table('library_query_clusters')->where('status', 'active')->groupBy('service_id')->selectRaw('service_id, count(*) as n')->pluck('n', 'service_id');
        $brandCounts = DB::table('brand_offerings')->where('status', 'active')->whereNotNull('service_catalog_item_id')->groupBy('service_catalog_item_id')
            ->selectRaw('service_catalog_item_id, count(distinct brand_id) as n')->pluck('n', 'service_catalog_item_id');
        $ids = collect($clusterCounts->keys())->merge($brandCounts->keys())->unique();

        return ServiceCatalogItem::query()->where('status', 'active')->whereIn('id', $ids)->with('primaryName')->get()
            ->map(fn (ServiceCatalogItem $s): array => [
                'id' => (int) $s->id, 'name' => (string) ($s->primaryName?->raw_label ?? '#'.$s->id), 'sector' => (string) $s->sector,
                'clusters' => (int) ($clusterCounts[$s->id] ?? 0), 'brands' => (int) ($brandCounts[$s->id] ?? 0),
            ])->sortBy([['brands', 'desc'], ['name', 'asc']])->values()->all();
    }

    /** @return array<string, mixed> */
    public function forService(int $serviceId): array
    {
        $clusters = $this->clusters->all([$serviceId]);
        $sites = $this->sites->websites($serviceId);
        $targets = DB::table('library_cluster_targets')->where('service_id', $serviceId)->where('url', '!=', '')->get()
            ->mapWithKeys(fn ($t): array => [$t->cluster_key.'|'.$t->digital_asset_id => ['url' => $t->url, 'source' => $t->source]]);
        $cannibal = DB::table('brain_cannibalizations')->where('service_id', $serviceId)->where('status', 'open')->get()
            ->map(fn ($r): array => ['id' => (int) $r->id, 'site_id' => (int) $r->digital_asset_id, 'cluster_id' => (int) $r->cluster_id, 'subject' => $r->subject,
                'impressions' => (int) $r->impressions, 'pages' => (array) json_decode((string) $r->pages, true)]);
        $unclustered = (int) DB::table('search_query_library_item_service')->where('service_catalog_item_id', $serviceId)->whereNull('library_cluster_id')->count();
        $order = ['main' => 0, 'landing' => 1, 'support' => 2, 'faq' => 3];
        $clusters = collect($clusters)->sortBy(fn (array $c): string => ($order[$c['page_type']] ?? 4).'-'.str_pad((string) (100000 - $c['queries']), 6, '0', STR_PAD_LEFT))->values()->all();

        $adGroups = DB::table('brain_ad_group_clusters as g')->leftJoin('brands as b', 'b.id', '=', 'g.brand_id')->where('g.service_id', $serviceId)
            ->orderByDesc('g.cost')->get(['g.*', 'b.name as brand_name'])
            ->map(fn ($g): array => ['cluster_id' => $g->cluster_id !== null ? (int) $g->cluster_id : null, 'brand_id' => (int) $g->brand_id, 'brand' => (string) $g->brand_name,
                'name' => (string) $g->ad_group_name, 'share' => $g->cluster_share !== null ? (float) $g->cluster_share : null, 'final_url' => $g->final_url,
                'url_matches' => $g->url_matches === null ? null : (bool) $g->url_matches, 'qs' => $g->quality_score !== null ? (float) $g->quality_score : null,
                'cost' => (float) $g->cost, 'conversions' => (float) $g->conversions, 'clicks' => (int) $g->clicks])->all();
        $meta = DB::table('brain_meta_ads as m')->leftJoin('brands as b', 'b.id', '=', 'm.brand_id')->where('m.service_id', $serviceId)
            ->groupBy('m.brand_id', 'b.name', 'm.angle')->selectRaw('m.brand_id, b.name as brand_name, m.angle, count(*) as ads, sum(m.spend) as spend, sum(m.results) as results, sum(m.impressions) as impressions')
            ->get()->map(fn ($r): array => ['brand' => (string) $r->brand_name, 'angle' => $r->angle, 'ads' => (int) $r->ads, 'spend' => (float) $r->spend,
                'results' => (float) $r->results, 'impressions' => (int) $r->impressions, 'cpr' => (float) $r->results > 0 ? round((float) $r->spend / (float) $r->results, 2) : null])->all();
        $recommendations = DB::table('brain_recommendations as r')->leftJoin('brands as b', 'b.id', '=', 'r.brand_id')->where('r.service_id', $serviceId)->where('r.status', 'open')
            ->orderByRaw('coalesce(r.impact, 0) desc')->limit(50)->get(['r.id', 'r.channel', 'r.type', 'r.title', 'r.detail', 'r.basis', 'r.impact', 'b.name as brand_name'])->map(fn ($r): array => (array) $r)->all();

        $period = DB::table('brain_success_snapshots')->where('service_id', $serviceId)->max('period');
        $features = DB::table('brain_page_features')->whereIn('digital_asset_id', $sites->keys())->get()
            ->mapWithKeys(fn ($f): array => [$f->digital_asset_id.'|'.$f->url_key => ['features' => (array) json_decode((string) $f->features, true), 'ai' => $f->ai_features !== null ? (array) json_decode((string) $f->ai_features, true) : null]]);
        $success = $period === null ? [] : DB::table('brain_success_snapshots')->where('service_id', $serviceId)->where('period', $period)->get()
            ->mapWithKeys(fn ($r): array => [$r->cluster_id.'|'.$r->digital_asset_id => [
                'score' => $r->score !== null ? (float) $r->score : null, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks,
                'position' => $r->position !== null ? (float) $r->position : null, 'ctr_index' => $r->ctr_index !== null ? (float) $r->ctr_index : null,
                'sessions' => (int) $r->sessions, 'conversions' => (float) $r->conversions, 'url' => $r->url, 'cohort' => (int) $r->cohort_size,
                'page' => $r->url !== null ? ($features[$r->digital_asset_id.'|'.SeoText::urlKey((string) $r->url)] ?? null) : null,
            ]])->all();

        return [
            'success' => $success,
            'period' => $period,
            'ad_groups' => $adGroups,
            'meta' => $meta,
            'recommendations' => $recommendations,
            'clusters' => $clusters,
            'sites' => $sites->map(fn ($site): array => ['id' => (int) $site->id, 'name' => (string) $site->name, 'brand' => (string) ($site->brand?->name ?? '')])->values()->all(),
            'targets' => $targets->all(),
            'cannibalizations' => $cannibal->values()->all(),
            'unclustered' => $unclustered,
        ];
    }
}
