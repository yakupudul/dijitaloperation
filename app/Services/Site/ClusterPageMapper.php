<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ClusterPagesAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\Queries\QueryPipeline;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;

/**
 * AI adım 2 — küme ↔ sayfa (`brand_cluster_pages`): every approved cluster of the brand's services gets, per site
 * language, a target URL (the operator may add more) and one of 7 states. Deterministic signals first — Search Console query × page facts of the cluster's queries
 * (impressions share per page, position, ranking URLs), the service ↔ page links of adım 1 and the subtopic coverage of
 * the page text —, then ONE AI call per service (`site.cluster_pages`) judges coverage / intent of the ambiguous ones.
 * Target query = main query, with the brand's target area in front only for commercial / local intent (a brand-only
 * override wins). Operator rows are locked: only their numbers are refreshed; rows excluded for the brand are skipped.
 * Operator edits go through ClusterEditor.
 */
final class ClusterPageMapper
{
    public const int MIN_IMPRESSIONS = 30;

    public const float CONFLICT_SHARE = 0.25;

    public const float WRONG_PAGE_SHARE = 0.5;

    public const float WEAK_POSITION = 10.0;

    public const float THIN = 0.4;

    public const float SURE = 0.7;

    private const int MAX_CONTENT = 6000;

    public function __construct(
        private readonly SiteAi $ai,
        private readonly SiteMetrics $metrics,
        private readonly QueryPipeline $queries,
    ) {}

    /** @return array{status: string, clusters: int, ai: int} status: ready | no_brand | no_clusters | ai_* */
    public function refresh(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        if ($brand === null || $brand->sector_id === null) {
            return ['status' => 'no_brand', 'clusters' => 0, 'ai' => 0];
        }
        $offerings = SiteScope::offerings($brand);
        $serviceOffering = $offerings->filter(fn (BrandOffering $o): bool => $o->service_catalog_item_id !== null)
            ->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->service_catalog_item_id => (int) $o->id]);
        $clusters = Cluster::query()->with(['mainQuery', 'clusterQueries'])->where('approved', true)->where('sector_id', $brand->sector_id)
            ->whereIn('service_id', $serviceOffering->keys())->orderBy('service_id')->orderBy('id')->get();
        SiteMetrics::forgetPageTotals((int) $site->id);
        if ($clusters->isEmpty()) {
            BrandClusterPage::query()->where('brand_id', $brand->id)->where('website_asset_id', $site->id)->where('locked', false)->where('excluded', false)->delete();
            $this->queries->brandTargets((int) $brand->id);

            return ['status' => 'no_clusters', 'clusters' => 0, 'ai' => 0];
        }
        $area = SiteScope::targetArea($brand);
        $hasGsc = $this->metrics->window($brand) !== null;
        $queryIds = $clusters->flatMap(fn (Cluster $c): Collection => $c->clusterQueries->where('is_suggested', false)->pluck('query_id'))->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $facts = collect($this->metrics->queryPageFacts($brand, $site, $queryIds))->groupBy('query_id');

        // Every site language gets its own rows (language versions are separate targets).
        $keep = [];
        $groups = [];
        foreach (SiteScope::languages($site) ?: [null] as $language) {
            foreach ($this->mapLanguage($brand, $site, $clusters, $serviceOffering, $facts, $area, $language, $hasGsc, $keep) as $group) {
                $groups[] = $group;
            }
        }
        BrandClusterPage::query()->where('brand_id', $brand->id)->where('website_asset_id', $site->id)->where('locked', false)->whereNotIn('id', $keep ?: [0])->delete();

        $status = 'ready';
        $aiCount = 0;
        if ($groups !== [] && SiteScope::aiAllowed($brand)) {
            foreach ($groups as $group) {
                [$callStatus, $count] = $this->judge($brand, $group['items'], $group['byKey'], $hasGsc);
                $aiCount += $count;
                if ($callStatus !== 'ready') {
                    $status = 'ai_'.$callStatus;
                    break;
                }
            }
        }

        $this->queries->brandTargets((int) $brand->id); // brand_queries URLs follow the mapping

        return ['status' => $status, 'clusters' => count($keep), 'ai' => $aiCount];
    }

    /**
     * Rows of one language; returns the ambiguous clusters grouped per service for the AI.
     *
     * @param  Collection<int, Cluster>  $clusters
     * @param  Collection<int, int>  $serviceOffering
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $facts
     * @param  list<int>  $keep
     * @return list<array{items: list<array<string, mixed>>, byKey: Collection<string, Page>}>
     */
    private function mapLanguage(Brand $brand, DigitalAsset $site, Collection $clusters, Collection $serviceOffering, Collection $facts, mixed $area, ?string $language, bool $hasGsc, array &$keep): array
    {
        $pages = Page::query()->where('website_asset_id', $site->id)->when($language !== null, fn ($q) => $q->where(fn ($l) => $l->where('language', $language)->orWhereNull('language')))
            ->orderBy('id')->get(['id', 'url', 'path', 'title', 'h1', 'category', 'language']);
        $byKey = $pages->keyBy(fn (Page $p): string => SeoText::urlKey((string) $p->url));
        $pagesByOffering = OfferingPage::query()->whereNotNull('brand_offering_id')->whereIn('page_id', $pages->pluck('id'))->orderBy('id')->get()
            ->groupBy('brand_offering_id')->map(fn (Collection $links): array => $links->pluck('page_id')->map(fn ($id): int => (int) $id)->all());
        $existing = BrandClusterPage::query()->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->where(fn ($q) => $language === null ? $q->whereNull('language') : $q->where('language', $language))->orderBy('id')->get()->keyBy('cluster_id');

        $ambiguous = [];
        foreach ($clusters as $cluster) {
            $mapped = $pagesByOffering->get($serviceOffering->get((int) $cluster->service_id), []);
            $clusterFacts = $cluster->clusterQueries->where('is_suggested', false)->pluck('query_id')
                ->flatMap(fn ($id): Collection => $facts->get((int) $id, collect()))->values();
            $decision = $this->decide($cluster, $pages, $byKey, $mapped, $clusterFacts, $hasGsc);
            $row = $existing->get($cluster->id);
            if ($row !== null && $row->excluded) {
                $keep[] = $row->id; // excluded for this brand: kept as the operator left it

                continue;
            }
            $values = [
                'target_query' => filled($row?->target_query_override) ? (string) $row->target_query_override : self::targetQuery($cluster, $area),
                'clicks_28d' => $decision['metrics']['clicks'], 'impressions_28d' => $decision['metrics']['impressions'], 'position_28d' => $decision['metrics']['position'],
                'refreshed_at' => now(),
            ];
            if ($row !== null && $row->locked) {
                $pageMetrics = $row->page_id !== null ? $this->pageMetrics($clusterFacts, $byKey, (int) $row->page_id, $hasGsc) : $decision['metrics'];
                $row->forceFill(['target_query' => $values['target_query'], 'clicks_28d' => $pageMetrics['clicks'], 'impressions_28d' => $pageMetrics['impressions'], 'position_28d' => $pageMetrics['position'], 'refreshed_at' => now()])->save();
                $keep[] = $row->id;

                continue;
            }
            $values += ['page_id' => $decision['page_id'], 'state' => $decision['state'], 'reason' => mb_substr($decision['reason'], 0, 300), 'decided_by' => 'rule'];
            $row ??= new BrandClusterPage(['brand_id' => $brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $site->id, 'language' => $language]);
            $row->forceFill($values)->save();
            $keep[] = $row->id;
            if ($decision['candidates'] !== []) {
                $ambiguous[(int) $cluster->service_id][] = ['row' => $row, 'cluster' => $cluster, 'candidates' => $decision['candidates'], 'facts' => $clusterFacts];
            }
        }

        return array_values(array_map(fn (array $items): array => ['items' => $items, 'byKey' => $byKey], $ambiguous));
    }

    /** "ankara implant merkezi": area first, only for commercial / local intent. */
    public static function targetQuery(Cluster $cluster, mixed $area): string
    {
        $main = trim((string) ($cluster->mainQuery?->text ?? $cluster->name));
        $word = SiteScope::areaWord($area);
        if ($word === '' || ! in_array($cluster->intent, ['commercial', 'local'], true) || SeoText::containsPhrase($main, $word)) {
            return $main;
        }

        return $word.' '.$main;
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @param  Collection<string, Page>  $byKey
     * @param  list<int>  $mapped  pages linked to the cluster's service (adım 1)
     * @param  Collection<int, array<string, mixed>>  $facts  query × page facts of the cluster's queries
     * @return array{state: string, page_id: ?int, reason: string, metrics: array{clicks: ?int, impressions: ?int, position: ?float}, candidates: list<int>}
     */
    public function decide(Cluster $cluster, Collection $pages, Collection $byKey, array $mapped, Collection $facts, bool $hasGsc): array
    {
        $perPage = [];
        $total = 0;
        foreach ($facts as $fact) {
            $total += (int) $fact['impressions'];
            $page = $byKey->get($fact['url_key']);
            $key = $page !== null ? (int) $page->id : 'x:'.$fact['url_key'];
            $current = $perPage[$key] ?? ['impressions' => 0, 'clicks' => 0, 'weighted' => 0.0, 'weight' => 0, 'path' => $page?->path ?? SeoText::urlPath((string) $fact['url'])];
            $current['impressions'] += (int) $fact['impressions'];
            $current['clicks'] += (int) $fact['clicks'];
            if ($fact['position'] !== null) {
                $current['weighted'] += (float) $fact['position'] * (int) $fact['impressions'];
                $current['weight'] += (int) $fact['impressions'];
            }
            $perPage[$key] = $current;
        }
        uasort($perPage, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        $ranking = array_filter($perPage, fn (array $row, int|string $key): bool => is_int($key), ARRAY_FILTER_USE_BOTH);
        $share = fn (int|string $key): float => $total > 0 ? $perPage[$key]['impressions'] / $total : 0.0;
        $pct = fn (float $value): string => '%'.(int) round($value * 100);
        $main = (string) ($cluster->mainQuery?->text ?? $cluster->name);
        $none = ['clicks' => $hasGsc ? 0 : null, 'impressions' => $hasGsc ? 0 : null, 'position' => null];
        $metricsOf = function (?int $pageId) use ($perPage, $hasGsc, $none): array {
            if ($pageId === null || ! isset($perPage[$pageId])) {
                return $none;
            }
            $row = $perPage[$pageId];

            return ['clicks' => $hasGsc ? $row['clicks'] : null, 'impressions' => $hasGsc ? $row['impressions'] : null, 'position' => $row['weight'] > 0 ? round($row['weighted'] / $row['weight'], 1) : null];
        };

        // Target: a page of the service (most impressions, else best name match), else the page Google shows most.
        $target = null;
        if ($mapped !== []) {
            $withData = array_values(array_filter(array_keys($ranking), fn (int $id): bool => in_array($id, $mapped, true)));
            $target = $withData[0] ?? collect($mapped)->sortByDesc(fn (int $id): float => SiteText::overlap(SiteText::pageName($pages->firstWhere('id', $id) ?? new Page), $main))->first();
        } elseif ($ranking !== []) {
            $target = (int) array_key_first($ranking);
        }
        if ($target === null) {
            $candidates = $pages->filter(fn (Page $p): bool => in_array($p->category, ['hizmet', 'lokasyon', 'blog', 'sss'], true) && SiteText::overlap(SiteText::pageName($p), $main) >= 0.5)
                ->take(5)->pluck('id')->map(fn ($id): int => (int) $id)->all();

            return ['state' => 'no_page', 'page_id' => null, 'reason' => $candidates === [] ? 'Sitede bu ihtiyaca ayrılmış sayfa yok.' : 'Hizmete bağlı sayfa yok; benzer adlı sayfa AI ile kontrol ediliyor.',
                'metrics' => $none, 'candidates' => $candidates];
        }
        $metrics = $metricsOf($target);
        $targetPath = (string) ($pages->firstWhere('id', $target)?->path ?? '');

        if ($hasGsc && $total >= self::MIN_IMPRESSIONS) {
            $sharing = array_filter(array_keys($perPage), fn (int|string $key): bool => $share($key) >= self::CONFLICT_SHARE);
            if (count($sharing) >= 2) {
                return ['state' => 'possible_conflict', 'page_id' => $target, 'metrics' => $metrics, 'candidates' => [],
                    'reason' => count($sharing).' sayfa gösterim paylaşıyor: '.implode(', ', array_map(fn ($key): string => $perPage[$key]['path'].' '.$pct($share($key)), array_slice($sharing, 0, 3))).'.'];
            }
            $top = array_key_first($perPage);
            if ($mapped !== [] && ! in_array($top, $mapped, true) && $share($top) >= self::WRONG_PAGE_SHARE) {
                return ['state' => 'wrong_page', 'page_id' => $target, 'metrics' => $metrics, 'candidates' => [],
                    'reason' => 'Google '.$perPage[$top]['path'].' sayfasını gösteriyor ('.$pct($share($top)).'); hedef '.$targetPath.'.'];
            }
        }

        $coverage = $this->coverage($target, $cluster, $main);
        if ($coverage < self::THIN) {
            return ['state' => 'thin_coverage', 'page_id' => $target, 'metrics' => $metrics, 'candidates' => [],
                'reason' => 'Alt konuların '.$pct($coverage).'’i sayfada.'];
        }
        if (! $hasGsc || $total < self::MIN_IMPRESSIONS) {
            return ['state' => 'insufficient_data', 'page_id' => $target, 'metrics' => $metrics, 'candidates' => [],
                'reason' => $hasGsc ? '28 günde '.$total.' gösterim.' : 'Search Console verisi yok.'];
        }
        if ($metrics['position'] !== null && $metrics['position'] > self::WEAK_POSITION) {
            return ['state' => 'weak_performance', 'page_id' => $target, 'metrics' => $metrics, 'candidates' => [],
                'reason' => 'Ortalama pozisyon '.number_format($metrics['position'], 1, ',', '.').'.'];
        }
        if ($coverage < self::SURE) {
            return ['state' => 'thin_coverage', 'page_id' => $target, 'metrics' => $metrics, 'candidates' => [$target],
                'reason' => 'Alt konuların '.$pct($coverage).'’i sayfada; AI ile kontrol ediliyor.'];
        }

        return ['state' => 'sufficient', 'page_id' => $target, 'metrics' => $metrics, 'candidates' => [],
            'reason' => 'Pozisyon '.($metrics['position'] !== null ? number_format($metrics['position'], 1, ',', '.') : '—').' · alt konuların '.$pct($coverage).'’i sayfada.'];
    }

    private function coverage(int $pageId, Cluster $cluster, string $main): float
    {
        $page = Page::query()->find($pageId, ['id', 'title', 'h1', 'headings', 'content_text']);
        if ($page === null) {
            return 0.0;
        }
        $text = mb_substr((string) $page->title.' '.$page->h1.' '.collect((array) $page->headings)->pluck('text')->implode(' ').' '.$page->content_text, 0, 40000);
        $subtopics = array_values(array_filter((array) $cluster->subtopics, 'is_string'));

        return $subtopics === [] ? SiteText::overlap($text, $main) : SiteText::coverage($text, $subtopics);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $facts
     * @param  Collection<string, Page>  $byKey
     * @return array{clicks: ?int, impressions: ?int, position: ?float}
     */
    private function pageMetrics(Collection $facts, Collection $byKey, int $pageId, bool $hasGsc): array
    {
        $rows = $facts->filter(fn (array $f): bool => (int) ($byKey->get($f['url_key'])?->id ?? 0) === $pageId);
        $weight = $rows->filter(fn (array $f): bool => $f['position'] !== null)->sum('impressions');

        return ['clicks' => $hasGsc ? (int) $rows->sum('clicks') : null, 'impressions' => $hasGsc ? (int) $rows->sum('impressions') : null,
            'position' => $weight > 0 ? round($rows->sum(fn (array $f): float => (float) $f['position'] * $f['impressions']) / $weight, 1) : null];
    }

    /**
     * One call for the ambiguous clusters of one service; the answer must name a candidate page (or none).
     *
     * @param  list<array{row: BrandClusterPage, cluster: Cluster, candidates: list<int>, facts: Collection<int, array<string, mixed>>}>  $items
     * @param  Collection<string, Page>  $byKey
     * @return array{0: string, 1: int}
     */
    private function judge(Brand $brand, array $items, Collection $byKey, bool $hasGsc): array
    {
        $candidateIds = collect($items)->flatMap(fn (array $i): array => $i['candidates'])->unique()->values()->all();
        $pages = Page::query()->whereIn('id', $candidateIds)->get(['id', 'url', 'title', 'h1', 'headings', 'content_text', 'content_summary'])->keyBy('id');
        $result = $this->ai->run(new ClusterPagesAgent, [
            'brand' => $brand->name,
            'clusters' => array_map(fn (array $i): array => [
                'cluster_id' => (int) $i['cluster']->id, 'name' => (string) $i['cluster']->name, 'intent' => (string) $i['cluster']->intent,
                'page_type' => (string) $i['cluster']->page_type, 'main_query' => (string) ($i['cluster']->mainQuery?->text ?? ''),
                'subtopics' => array_values((array) $i['cluster']->subtopics), 'candidate_page_ids' => $i['candidates'],
            ], $items),
            'pages' => $pages->map(fn (Page $p): array => ['id' => (int) $p->id, 'url' => (string) $p->url, 'title' => $p->title, 'h1' => $p->h1,
                'headings' => collect((array) $p->headings)->pluck('text')->take(30)->values()->all(),
                'content' => mb_substr((string) ($p->content_text ?? ''), 0, self::MAX_CONTENT)])->values()->all(),
        ], 240);
        if ($result['status'] !== 'ready') {
            return [$result['status'], 0];
        }
        $byCluster = collect($items)->keyBy(fn (array $i): int => (int) $i['cluster']->id);
        $done = 0;
        foreach ((array) ($result['data']['clusters'] ?? []) as $answer) {
            $clusterId = is_array($answer) && is_int($answer['cluster_id'] ?? null) ? $answer['cluster_id'] : null;
            $item = $clusterId !== null ? $byCluster->get($clusterId) : null;
            $state = is_array($answer) ? ($answer['state'] ?? null) : null;
            if ($item === null || ! in_array($state, ClusterPagesAgent::STATES, true)) {
                continue;
            }
            $pageId = is_int($answer['page_id'] ?? null) && in_array($answer['page_id'], $item['candidates'], true) ? $answer['page_id'] : null;
            if ($state !== 'no_page' && $pageId === null) {
                continue; // a page state must name one of the candidates
            }
            $row = $item['row']->fresh();
            if ($row === null || $row->locked) {
                continue;
            }
            $metrics = $pageId !== null ? $this->pageMetrics($item['facts'], $byKey, $pageId, $hasGsc) : ['clicks' => $row->clicks_28d, 'impressions' => $row->impressions_28d, 'position' => null];
            $evidence = new SiteEvidence($pages->pluck('url')->map(fn ($u): string => (string) $u)->all(), [...array_values($metrics), ...$item['facts']->pluck('impressions')->all(), ...$item['facts']->pluck('clicks')->all()]);
            $reason = mb_substr(trim((string) ($answer['reason'] ?? '')), 0, 300);
            $row->forceFill([
                'page_id' => $state === 'no_page' ? null : $pageId, 'state' => $state, 'decided_by' => 'ai',
                // An invented URL or number never reaches the screen.
                'reason' => $reason !== '' && $evidence->grounded($reason) ? $reason : 'AI kapsam / niyet değerlendirmesi.',
                'clicks_28d' => $metrics['clicks'], 'impressions_28d' => $metrics['impressions'], 'position_28d' => $metrics['position'],
            ])->save();
            $done++;
        }

        return ['ready', $done];
    }
}
