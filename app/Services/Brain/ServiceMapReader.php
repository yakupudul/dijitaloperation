<?php

namespace App\Services\Brain;

use App\Models\ServiceCatalogItem;
use App\Services\Brain\Clustering\ServiceClusters;
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

        return [
            'clusters' => $clusters,
            'sites' => $sites->map(fn ($site): array => ['id' => (int) $site->id, 'name' => (string) $site->name, 'brand' => (string) ($site->brand?->name ?? '')])->values()->all(),
            'targets' => $targets->all(),
            'cannibalizations' => $cannibal->values()->all(),
            'unclustered' => $unclustered,
        ];
    }
}
