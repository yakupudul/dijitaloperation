<?php

namespace App\Services\Analyst\Search;

use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\SeoTask;
use App\Models\TopicCluster;
use App\Models\WebsiteUrlAudit;
use App\Models\WebsiteUrlVerdict;
use App\Services\ContentStudio\ContentIdeaPlanner;
use App\Services\ContentStudio\TopicText;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stored-data reads of the Arama tab (no provider or AI call): the Durum numbers, the service × area matrix and the
 * rows that go into the search pack. Shared by SearchAnalyst (pack) and the SearchTab (Durum / Kanıt), so the numbers
 * on screen are the numbers the AI saw.
 */
final class SearchFacts
{
    public const int WINDOW_DAYS = 28;

    public const int TOP_N = 10;

    public function __construct(
        private readonly SeoPlanInputCollector $seo,
        private readonly ContentIdeaPlanner $planner,
    ) {}

    public function website(Brand $brand): ?DigitalAsset
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->where('status', 'active')->orderBy('id')->first();
    }

    /** One-line "Veri yok" reason, or null when the tab has data. */
    public function missing(Brand $brand, ?DigitalAsset $site): ?string
    {
        if ($site === null) {
            return 'Veri yok: markaya bağlı web sitesi yok.';
        }
        $bound = CoreAssetBinding::query()->where('digital_asset_id', $site->id)->where('capability', 'search_console')->where('status', CoreAssetBinding::STATUS_ACTIVE)->exists();
        if (! $bound && ! DB::table('gsc_property_daily')->where('digital_asset_id', $site->id)->exists()) {
            return 'Veri yok: Search Console bağlı değil.';
        }

        return null;
    }

    /** @return array{current: int, previous: int, delta_pct: int|null, end: string|null} */
    public function organicClicks(DigitalAsset $site): array
    {
        $latest = $this->seo->scopeGsc(DB::table('gsc_property_daily'), $site, 'gsc_property_daily')->max('reporting_date');
        if ($latest === null) {
            return ['current' => 0, 'previous' => 0, 'delta_pct' => null, 'end' => null];
        }
        $end = CarbonImmutable::parse((string) $latest);
        $sum = fn (CarbonImmutable $from, CarbonImmutable $to): int => (int) $this->seo->scopeGsc(DB::table('gsc_property_daily'), $site, 'gsc_property_daily')
            ->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])->sum('clicks');
        $current = $sum($end->subDays(self::WINDOW_DAYS - 1), $end);
        $previous = $sum($end->subDays(2 * self::WINDOW_DAYS - 1), $end->subDays(self::WINDOW_DAYS));

        return ['current' => $current, 'previous' => $previous, 'delta_pct' => $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null, 'end' => $end->toDateString()];
    }

    /**
     * Core queries of the brand's services: relevant, not branded, under one of the brand's services (the brand view
     * of the one core-query store). Most valuable first.
     *
     * @return Builder<BrandDemandQuery>
     */
    public function coreQueries(Brand $brand): Builder
    {
        return BrandDemandQuery::query()->where('brand_id', $brand->id)->where('relevance', BrandDemandQuery::RELEVANT)
            ->where('is_branded', false)->whereNotNull('brand_offering_id')
            ->whereIn('brand_offering_id', BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->select('id'));
    }

    /** @return array{total: int, ranking: int, pct: int|null} ranking = Search Console average position ≤ 10 */
    public function queryCoverage(Brand $brand): array
    {
        $total = $this->coreQueries($brand)->count();
        $ranking = $this->coreQueries($brand)->whereNotNull('gsc_position')->where('gsc_position', '>', 0)->where('gsc_position', '<=', self::TOP_N)->count();

        return ['total' => $total, 'ranking' => $ranking, 'pct' => $total > 0 ? (int) round($ranking / $total * 100) : null];
    }

    /**
     * Hizmet × bölge: every active service × active service area. A cell is covered when a core query of that
     * service in that area ranks ≤ 10 (position shown) or a published page names both (✓).
     *
     * @return array{services: array<int, string>, areas: array<int, string>, cells: array<string, array{service_id: int, area_id: int, status: string, position: float|null, url: string|null}>, covered: int, total: int, pct: int|null}
     */
    public function matrix(Brand $brand, DigitalAsset $site): array
    {
        $services = [];
        $names = [];
        $plannerServices = $this->planner->services($site);
        foreach (BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->orderByDesc('is_priority')->orderBy('id')->get() as $offering) {
            $services[(int) $offering->id] = $offering->displayName();
            $names[(int) $offering->id] = $plannerServices[(int) $offering->id]['names'] ?? [$offering->displayName()];
        }
        $areas = [];
        foreach (BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderByRaw('CASE WHEN priority_rank IS NULL THEN 1 ELSE 0 END')->orderBy('priority_rank')->orderBy('id')->get() as $area) {
            $name = trim((string) ($area->district_name ?: $area->city_name));
            if ($name !== '') {
                $areas[(int) $area->id] = $name;
            }
        }
        $positions = [];
        foreach ($this->coreQueries($brand)->whereNotNull('brand_service_area_id')->whereNotNull('gsc_position')->where('gsc_position', '>', 0)
            ->get(['brand_offering_id', 'brand_service_area_id', 'gsc_position']) as $row) {
            $key = $row->brand_offering_id.':'.$row->brand_service_area_id;
            $positions[$key] = min($positions[$key] ?? PHP_FLOAT_MAX, (float) $row->gsc_position);
        }
        $items = $services !== [] && $areas !== [] ? $this->planner->inventory($site)->items() : [];
        $cells = [];
        $covered = 0;
        foreach ($services as $serviceId => $serviceName) {
            foreach ($areas as $areaId => $areaName) {
                $position = $positions[$serviceId.':'.$areaId] ?? null;
                $url = $this->pageNaming($items, $areaName, $names[$serviceId]);
                $status = $position !== null && $position <= self::TOP_N ? 'rank' : ($url !== null ? 'page' : 'none');
                if ($status !== 'none') {
                    $covered++;
                }
                $cells[$serviceId.':'.$areaId] = ['service_id' => $serviceId, 'area_id' => $areaId, 'status' => $status,
                    'position' => $position !== null ? round($position, 1) : null, 'url' => $url];
            }
        }
        $total = count($cells);

        return ['services' => $services, 'areas' => $areas, 'cells' => $cells, 'covered' => $covered, 'total' => $total, 'pct' => $total > 0 ? (int) round($covered / $total * 100) : null];
    }

    /**
     * Failing critical standards (state fail, severity high): standard id => rule, URL count, example path. Site-level
     * checks of the URL audit count too.
     *
     * @return array<string, array{rule: string, urls: int, example: string|null}>
     */
    public function failingStandards(DigitalAsset $site): array
    {
        $out = [];
        foreach (WebsiteUrlVerdict::query()->where('digital_asset_id', $site->id)->whereIn('verdict', [WebsiteUrlVerdict::FIX, WebsiteUrlVerdict::CHECK, WebsiteUrlVerdict::STRENGTHEN])
            ->orderByDesc('priority')->limit(2000)->get(['path', 'findings']) as $verdict) {
            foreach ((array) $verdict->findings as $finding) {
                if (! in_array($finding['source'] ?? null, ['standard', 'stored_standard'], true) || ($finding['state'] ?? null) !== 'fail' || ($finding['severity'] ?? null) !== 'high') {
                    continue;
                }
                $id = (string) $finding['id'];
                $out[$id] ??= ['rule' => mb_substr((string) ($finding['rule'] ?? $id), 0, 120), 'urls' => 0, 'example' => (string) $verdict->path];
                $out[$id]['urls']++;
            }
        }
        $audit = WebsiteUrlAudit::query()->where('digital_asset_id', $site->id)->first();
        foreach ((array) ($audit?->site_checks ?? []) as $id => $check) {
            if (is_array($check) && ($check['state'] ?? null) === 'fail' && in_array($check['severity'] ?? null, ['high', 'critical'], true)) {
                $out[(string) $id] ??= ['rule' => mb_substr((string) ($check['title'] ?? $check['rule'] ?? $id), 0, 120), 'urls' => 0, 'example' => null];
            }
        }
        uasort($out, fn (array $a, array $b): int => $b['urls'] <=> $a['urls']);

        return $out;
    }

    public function contentOpportunities(DigitalAsset $site): int
    {
        return TopicCluster::query()->where('digital_asset_id', $site->id)->where('status', 'active')->whereIn('verdict', ['new', 'strengthen'])->count();
    }

    /** @return Collection<int, TopicCluster> */
    public function topics(DigitalAsset $site, int $limit = 60): Collection
    {
        return TopicCluster::query()->with('offering')->where('digital_asset_id', $site->id)->where('status', 'active')->where('verdict', '!=', 'none')
            ->orderByDesc('demand_score')->orderBy('id')->limit($limit)->get();
    }

    /** @return Collection<int, object> brand clusters of the one query store (library_query_clusters) with the brand's impressions */
    public function libraryClusters(Brand $brand, int $limit = 40): Collection
    {
        $serviceIds = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id', 'id');
        if ($serviceIds->isEmpty()) {
            return collect();
        }
        $impressions = DB::table('brand_demand_queries as q')
            ->join('search_query_library_item_service as s', 's.search_query_library_item_id', '=', 'q.search_query_library_item_id')
            ->where('q.brand_id', $brand->id)->where('q.relevance', BrandDemandQuery::RELEVANT)->where('q.is_branded', false)->whereNotNull('s.library_cluster_id')
            ->whereIn('s.service_catalog_item_id', $serviceIds->values()->all())
            ->groupBy('s.library_cluster_id')->selectRaw('s.library_cluster_id as cluster_id, sum(q.gsc_impressions) as impressions, count(*) as queries')->get()->keyBy('cluster_id');
        $offeringByService = $serviceIds->flip();

        return DB::table('library_query_clusters')->whereIn('service_id', $serviceIds->values()->all())->where('status', 'active')->get()
            ->map(function (object $c) use ($impressions, $offeringByService): object {
                $c->brand_impressions = (int) ($impressions->get($c->id)->impressions ?? 0);
                $c->brand_queries = (int) ($impressions->get($c->id)->queries ?? 0);
                $c->offering_id = $offeringByService->get($c->service_id);

                return $c;
            })
            ->sortByDesc('brand_impressions')->take($limit)->values();
    }

    /** @return Collection<int, WebsiteUrlVerdict> non-OK verdicts by priority, then the top-traffic pages */
    public function urls(DigitalAsset $site, int $problems = 50, int $top = 20): Collection
    {
        $bad = WebsiteUrlVerdict::query()->where('digital_asset_id', $site->id)->where('verdict', '!=', WebsiteUrlVerdict::OK)->orderByDesc('priority')->limit($problems)->get();
        $best = WebsiteUrlVerdict::query()->where('digital_asset_id', $site->id)->orderByDesc('clicks')->limit($top)->get();

        return $bad->concat($best)->unique('id')->values();
    }

    /** @return Collection<int, SeoTask> */
    public function openTasks(DigitalAsset $site, int $limit = 30): Collection
    {
        return SeoTask::query()->where('digital_asset_id', $site->id)->where('status', 'open')->orderByDesc('priority_score')->limit($limit)->get();
    }

    /** @return array{pages: int, posts: int, last_post: string|null, days_since_last_post: int|null} */
    public function inventory(DigitalAsset $site): array
    {
        $items = $this->planner->inventory($site)->items();
        $posts = count(array_filter($items, fn (array $i): bool => $i['kind'] === 'post'));
        $last = Schema::hasTable('website_cms_object_snapshot')
            ? DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->where('object_type', 'post')->where('status', 'publish')->max('published_at')
            : null;
        $lastDate = $last !== null ? CarbonImmutable::parse((string) $last) : null;

        return ['pages' => count($items) - $posts, 'posts' => $posts, 'last_post' => $lastDate?->toDateString(), 'days_since_last_post' => $lastDate !== null ? (int) $lastDate->diffInDays(now()) : null];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $names
     */
    private function pageNaming(array $items, string $area, array $names): ?string
    {
        $folded = SeoText::fold($area);
        foreach ($items as $item) {
            $text = SeoText::fold($item['title'].' '.$item['h1'].' '.$item['slug']);
            if (! str_contains(' '.$text.' ', ' '.$folded.' ')) {
                continue;
            }
            foreach ($names as $name) {
                if (TopicText::containment($name, $text) >= 0.8) {
                    return (string) $item['url'];
                }
            }
        }

        return null;
    }
}
