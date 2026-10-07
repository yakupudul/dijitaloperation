<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ContentDiscoveryAgent;
use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Query;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Compliance\BriefCompliance;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Meta\MetaDesk;
use App\Services\Queries\QueryNormalizer;
use App\Services\SeoTasks\SeoText;
use App\Services\SeoTasks\SiteUrlPattern;
use App\Services\Site\Analysis\SiteAnalysisReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * İçerik: "Haftalık içerik öner" (`site.weekly_content`: main services, clusters without a suitable page / with thin
 * coverage, improvable URLs, previous plans of the last 8 weeks, month / season, weekly capacity) and "Kümeler dışında
 * fırsat keşfet" (`site.content_discovery`: brand queries outside every cluster) → content suggestions with title,
 * target cluster, page type, outline, the questions people ask AI assistants and the target URL (the site's own URL
 * pattern). "Kütüphaneye ekle" puts a discovery into the shared query / cluster library; "Taslak hazırla"
 * (`site.write_article`) writes the article, which passes the compliance gate before the WordPress draft.
 */
final class ContentPlanner
{
    public const array PAGE_TYPES = ['hizmet', 'blog', 'sss', 'lokasyon'];

    /** What makes a weekly idea more than "one more article" (shown on the title card). */
    public const array ANGLES = [
        'decision' => 'Karar desteği', 'comparison' => 'Karşılaştırma', 'process' => 'Süreç', 'local' => 'Yerel',
        'expert_answer' => 'AI aramalarına net yanıt', 'objection' => 'Endişe / yanlış bilinen', 'update' => 'Mevcut sayfayı güçlendir', 'insight' => 'SEO öngörüsü',
    ];

    private const array URL_TYPES = ['hizmet' => 'service', 'blog' => 'guide', 'sss' => 'faq', 'lokasyon' => 'location'];

    private const array CLUSTER_PAGE_TYPES = ['hizmet' => 'service', 'blog' => 'guide', 'sss' => 'faq', 'lokasyon' => 'location'];

    /** Gaps read per run; the best ones (brand's own searches, demand, main services) go to the AI. */
    private const int GAP_CANDIDATES = 300;

    private const int GAPS_TO_AI = 40;

    /** Search Console queries the site already shows for but not near the top: the strongest idea material. */
    private const int STRIKING_QUERIES = 40;

    /** A run that gave fewer ideas than asked (not enough evidence) is not asked again for this long. */
    public const int SHORT_RUN_DAYS = 7;

    private const array MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public function __construct(
        private readonly SiteAi $ai,
        private readonly BrandMemoryService $memory,
        private readonly SiteAnalysisReader $reader,
    ) {}

    public static function shortRunKey(int $siteId): string
    {
        return 'content-pool:short-run:'.$siteId;
    }

    /**
     * Open pool ideas whose titles read like generated text are closed (dismissed, so the next run never repeats them)
     * and the pool tops up with evidence-based ones. Approved, written or sent ideas are left alone.
     *
     * @return int ideas closed
     */
    public static function retireStyledIdeas(): int
    {
        $closed = 0;
        Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::OPEN)->orderBy('id')
            ->chunkById(500, function (Collection $ideas) use (&$closed): void {
                $ids = $ideas->filter(fn (Suggestion $s): bool => self::styleProblem((string) $s->title) !== null)->pluck('id')->all();
                if ($ids !== []) {
                    $closed += Suggestion::query()->whereIn('id', $ids)->update(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'operator_note' => 'Başlık kalıp gibiydi; kanıta dayalı fikirle değiştirildi.', 'updated_at' => now()]);
                }
            });

        return $closed;
    }

    /**
     * A title that reads like generated text, or null: two-part titles (dash, colon, slash), labels in brackets
     * ("(güncelleme)"), "kapsamlı rehber", "hizmet sayfası" and over-long titles never reach the pool.
     */
    public static function styleProblem(string $title): ?string
    {
        $folded = SeoText::fold($title);

        return match (true) {
            mb_strlen($title) > 70 => 'çok uzun',
            (bool) preg_match('/\s[—–-]\s|—|:|\s\/\s|[()\[\]]/u', $title) => 'iki parçalı başlık',
            (bool) preg_match('/kapsamli rehber|rehberi?$|hizmet sayfasi|lokasyon sayfasi|nedir kimlere|hakkinda her sey|bilmeniz gereken|\bfaq\b|ultimate guide|complete guide|everything you need/u', $folded) => 'kalıp ifade',
            default => null,
        };
    }

    /**
     * One run plans every language the pool asks for (`$wants`: language => ideas), so the clusters, queries and site
     * pages go to the AI once per site instead of once per language.
     *
     * @param  Collection<int, BrandClusterPage>|null  $only  "Konu üret": just these rows, one item
     * @param  array<string, int>|null  $wants  ideas per language; null: the brand's weekly number in the main language
     * @return array{status: string, added: int}
     */
    public function weekly(DigitalAsset $site, ?Collection $only = null, ?array $wants = null): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'added' => 0];
        }
        $siteLanguages = self::siteLanguages($site);
        $explicit = $wants !== null && $only === null;
        $wants = collect($explicit ? $wants : [])->filter(fn ($n, $l): bool => in_array($l, $siteLanguages, true) && (int) $n > 0)
            ->map(fn ($n): int => min(20, (int) $n))->all();
        if ($wants === []) {
            $explicit = false;
            $wants = [$siteLanguages[0] => $only !== null ? 1 : max(1, min(20, (int) ($brand->weekly_content_capacity ?? 4)))];
        }
        $capacity = array_sum($wants);
        $inLanguage = fn ($q) => ! $explicit ? $q : $q->where(fn ($l) => $l->whereNull('language')->orWhereIn('language', array_keys($wants)));
        $gaps = $only ?? $inLanguage(BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName', 'page:id,url'])->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->whereIn('state', ['no_page', 'thin_coverage'])->where('excluded', false))->orderBy('id')->limit(self::GAP_CANDIDATES)->get();
        $brandSearch = $only !== null ? [] : $this->brandSearch($site);
        $volumes = $this->clusterVolumes($gaps->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all());
        if ($only === null) {
            $gaps = $this->rankGaps($brand, $gaps, $volumes, $brandSearch)->take(self::GAPS_TO_AI)->values();
        }
        $improvable = $only !== null ? collect() : $inLanguage(BrandClusterPage::query()->with('page:id,url,title')->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->whereIn('state', ['weak_performance', 'thin_coverage'])->whereNotNull('page_id'))->limit(20)->get();
        // Earlier plans of 8 weeks and every title still waiting in the pool: never asked again.
        $previous = Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where(fn ($q) => $q->where('created_at', '>=', now()->subWeeks(8))->orWhereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED]))
            ->orderByDesc('id')->limit(150)->get(['title', 'status', 'action']);
        $sitePages = $this->sitePages($site);
        $topQueries = $this->clusterQueries($gaps->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all());
        $striking = $only !== null ? [] : $this->strikingQueries($site);
        $result = $this->ai->run(new WeeklyContentAgent, [
            'brand' => $this->memory->contextFor($brand, [], $gaps->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all())['profile'],
            'search_console' => $striking,
            'paid_results' => $only !== null ? [] : $this->paidResults($brand),
            'languages' => $wants,
            'capacity' => $capacity,
            'month' => self::MONTHS[(int) now()->month].' '.now()->year,
            'clusters' => $gaps->map(fn (BrandClusterPage $row): array => [
                'cluster_id' => (int) $row->cluster_id, 'language' => $row->language, 'name' => (string) $row->cluster?->name, 'service' => (string) ($row->cluster?->service?->primaryName?->raw_label ?? ''),
                'intent' => (string) $row->cluster?->intent, 'page_type' => (string) $row->cluster?->page_type, 'main_query' => (string) ($row->cluster?->mainQuery?->text ?? ''),
                'target_query' => $row->target_query, 'queries' => $topQueries[(int) $row->cluster_id] ?? [],
                'library_impressions' => $volumes[(int) $row->cluster_id] ?? 0, 'brand_search' => $brandSearch[(int) $row->cluster_id] ?? null,
                'state' => $row->stateLabel(), 'page_url' => $row->page?->url, 'subtopics' => array_values((array) $row->cluster?->subtopics),
                'gaps' => array_column((array) $row->gaps, 'text'),
                'ai_questions' => $row->cluster !== null ? ClusterAudit::aiQuestions($row->cluster, $brand) : [],
                'service_areas' => $row->cluster !== null ? ClusterAudit::serviceAreas($row->cluster, $brand) : [],
            ])->values()->all(),
            'improvable_urls' => $improvable->map(fn (BrandClusterPage $row): array => ['url' => (string) $row->page?->url, 'title' => $row->page?->title, 'state' => $row->stateLabel(), 'reason' => $row->reason])->values()->all(),
            'previous_plans' => $previous->map(fn (Suggestion $s): array => ['title' => $s->title, 'status' => $s->status])->values()->all(),
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title, 'category' => $p->category])->values()->all(),
        ], 240);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status'], 'added' => 0];
        }
        $clusters = $gaps->keyBy('cluster_id');
        $taken = $previous->map(fn (Suggestion $s): string => SeoText::fold((string) $s->title))->all();
        $added = 0;
        $left = $wants;
        foreach ((array) ($result['data']['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $language = is_string($item['language'] ?? null) && isset($left[strtolower($item['language'])]) ? strtolower($item['language']) : (count($wants) === 1 ? array_key_first($wants) : null);
            if ($language === null || $left[$language] < 1) {
                continue;
            }
            $clusterId = is_int($item['cluster_id'] ?? null) && $clusters->has($item['cluster_id']) ? $item['cluster_id'] : null;
            $row = $clusterId !== null ? $clusters->get($clusterId) : null;
            $evidence = $this->evidence($row, $volumes, $brandSearch, $striking, is_string($item['query'] ?? null) ? $item['query'] : null);
            if ($evidence === [] && ($item['kind'] ?? 'new') === 'update') {
                $page = $improvable->first(fn (BrandClusterPage $r): bool => SeoText::urlKey((string) $r->page?->url) === SeoText::urlKey((string) ($item['target_url'] ?? '')));
                $evidence = [['kind' => 'page', 'value' => $page !== null ? SeoText::urlPath((string) $page->page?->url).' · '.$page->stateLabel() : 'Sitedeki sayfa: '.SeoText::urlPath((string) ($item['target_url'] ?? '')), 'source' => 'site']];
            }
            if ($evidence === [] || self::styleProblem(trim((string) ($item['title'] ?? ''))) !== null) {
                continue; // no evidence (no query, cluster or page of the site) or a generated-looking title: never in the pool
            }
            $stored = $this->storeItem($brand, $site, $sitePages, $item, $taken, [
                'cluster_id' => $clusterId, 'out_of_cluster' => false, 'language' => $explicit ? $language : null,
                'angle' => in_array($item['angle'] ?? null, array_keys(self::ANGLES), true) ? $item['angle'] : null,
                'evidence' => $evidence,
                'service' => (string) ($row?->cluster?->service?->primaryName?->raw_label ?? ''),
            ], $result['prompt_version_id']);
            $added += $stored ? 1 : 0;
            $left[$language] -= $stored ? 1 : 0;
        }
        if ($only === null && $added < $capacity) {
            // Fewer than asked: the data does not carry more now; the daily top-up waits instead of asking for filler.
            Cache::put(self::shortRunKey((int) $site->id), now()->toIso8601String(), now()->addDays(self::SHORT_RUN_DAYS));
        }

        return ['status' => 'ready', 'added' => $added];
    }

    /**
     * Gaps best first: the brand's own Search Console impressions on the cluster, the cluster's search volume and the
     * weight of its service (main services, services with paid results).
     *
     * @param  Collection<int, BrandClusterPage>  $gaps
     * @param  array<int, int>  $volumes
     * @param  array<int, array{impressions: int, clicks: int, position: ?float}>  $brandSearch
     * @return Collection<int, BrandClusterPage>
     */
    private function rankGaps(Brand $brand, Collection $gaps, array $volumes, array $brandSearch): Collection
    {
        $main = BrandOffering::query()->where('brand_id', $brand->id)->where('priority', 'main')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id')->map(fn ($id): int => (int) $id)->all();
        $paid = array_flip(array_map(fn (array $r): int => $r['service_id'], $this->paidResults($brand)));

        return $gaps->sortByDesc(function (BrandClusterPage $row) use ($volumes, $brandSearch, $main, $paid): float {
            $serviceId = (int) ($row->cluster?->service_id ?? 0);
            $weight = 1.0 + (in_array($serviceId, $main, true) ? 1.0 : 0.0) + (isset($paid[$serviceId]) ? 0.5 : 0.0);

            return $weight * log10(1 + ($volumes[(int) $row->cluster_id] ?? 0)) + 1.5 * log10(1 + ($brandSearch[(int) $row->cluster_id]['impressions'] ?? 0));
        });
    }

    /**
     * Evidence of an idea with real numbers (shown under the title), or [] when it has none.
     *
     * @param  array<int, int>  $volumes
     * @param  array<int, array{impressions: int, clicks: int, position: ?float}>  $brandSearch
     * @param  list<array{query: string, impressions: int, clicks: int, position: ?float}>  $striking
     * @return list<array{kind: string, value: string, source: string}>
     */
    private function evidence(?BrandClusterPage $row, array $volumes, array $brandSearch, array $striking, ?string $query): array
    {
        $out = [];
        $number = fn (float $n, int $d = 0): string => number_format($n, $d, ',', '.');
        if ($query !== null) {
            foreach ($striking as $s) {
                if (SeoText::fold($s['query']) === SeoText::fold($query)) {
                    $out[] = ['kind' => 'query', 'value' => '«'.$s['query'].'» 28 günde '.$number($s['impressions']).' gösterim, '.$number($s['clicks']).' tıklama'
                        .($s['position'] !== null ? ', ortalama '.$number($s['position'], 1).'. sıra' : ''), 'source' => 'Search Console'];
                    break;
                }
            }
        }
        if ($row !== null) {
            $search = $brandSearch[(int) $row->cluster_id] ?? null;
            $volume = $volumes[(int) $row->cluster_id] ?? 0;
            $parts = array_filter([
                $volume > 0 ? 'sorgu kütüphanesinde '.$number($volume).' gösterim' : null,
                $search !== null && $search['impressions'] > 0 ? 'sitede 28 günde '.$number($search['impressions']).' gösterim'.($search['position'] !== null ? ', '.$number($search['position'], 1).'. sıra' : '') : null,
            ]);
            $out[] = ['kind' => 'cluster', 'value' => $row->cluster?->name.' · '.($parts !== [] ? implode(' · ', $parts) : $row->stateLabel()), 'source' => 'küme'];
        }

        return $out;
    }

    /**
     * The site's own Search Console numbers per cluster (28 days).
     *
     * @return array<int, array{impressions: int, clicks: int, position: ?float}>
     */
    private function brandSearch(DigitalAsset $site): array
    {
        $out = [];
        foreach ($this->reader->clusters($site, 28) as $row) {
            $out[(int) $row['cluster_id']] = ['impressions' => (int) $row['impressions'], 'clicks' => (int) $row['clicks'], 'position' => $row['position']];
        }

        return $out;
    }

    /**
     * Queries the site is seen for but not near the top (position 4–20, at least 30 impressions in 28 days), most seen
     * first: the reader already searches this, the brand answers it weakly.
     *
     * @return list<array{query: string, impressions: int, clicks: int, position: ?float}>
     */
    private function strikingQueries(DigitalAsset $site): array
    {
        return collect($this->reader->queries($site, 28))
            ->filter(fn (array $q): bool => $q['position'] !== null && $q['position'] >= 4 && $q['position'] <= 20 && $q['impressions'] >= 30)
            ->sortByDesc('impressions')->take(self::STRIKING_QUERIES)
            ->map(fn (array $q): array => ['query' => (string) $q['query'], 'impressions' => (int) $q['impressions'], 'clicks' => (int) $q['clicks'], 'position' => $q['position']])
            ->values()->all();
    }

    /**
     * Services that bring the brand results on Google Ads / Meta (30 days): where content pays off first.
     *
     * @return list<array{service_id: int, service: string, channel: string, result_type: string, results: float}>
     */
    private function paidResults(Brand $brand): array
    {
        if (! Schema::hasTable('ad_service_stats')) {
            return [];
        }
        $rows = DB::table('ad_service_stats')->where('brand_id', $brand->id)->whereNotNull('service_id')->where('results', '>', 0)->orderByDesc('results')->limit(20)
            ->get(['service_id', 'channel', 'result_type', 'results']);
        $names = MetaDesk::serviceNames($rows->pluck('service_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $rows->map(fn (object $r): array => ['service_id' => (int) $r->service_id, 'service' => $names[(int) $r->service_id] ?? '', 'channel' => (string) $r->channel,
            'result_type' => (string) $r->result_type, 'results' => (float) $r->results])->all();
    }

    /** @return array<int, int> cluster id => impressions of its visible queries in the query library */
    private function clusterVolumes(array $clusterIds): array
    {
        return DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds ?: [0])->where('q.hidden', false)
            ->groupBy('cq.cluster_id')->selectRaw('cq.cluster_id, sum(q.impressions) as volume')->pluck('volume', 'cluster_id')
            ->mapWithKeys(fn ($v, $id): array => [(int) $id => (int) $v])->all();
    }

    /** @return array{status: string, added: int} */
    public function discover(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'added' => 0];
        }
        $services = SiteScope::offerings($brand)->filter(fn (BrandOffering $o): bool => $o->service_catalog_item_id !== null)
            ->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->service_catalog_item_id => $o->displayName()]);
        $queries = DB::table('brand_queries as bq')->join('queries as q', 'q.id', '=', 'bq.query_id')
            ->where('bq.brand_id', $brand->id)->whereNull('bq.target_area_id')->where('q.hidden', false)->whereNotIn('q.id', ClusterQuery::query()->select('query_id'))
            ->orderByDesc('bq.impressions_28d')->limit(150)->get(['q.id', 'q.text', 'q.service_id', 'bq.impressions_28d', 'bq.clicks_28d']);
        if ($queries->isEmpty()) {
            return ['status' => 'no_queries', 'added' => 0];
        }
        $clusterNames = Cluster::query()->where('sector_id', $brand->sector_id)->whereIn('service_id', $services->keys())->orderBy('id')->limit(200)->pluck('name')->all();
        $sitePages = $this->sitePages($site);
        $result = $this->ai->run(new ContentDiscoveryAgent, [
            'brand' => $this->memory->contextFor($brand, [], [])['profile'],
            'services' => $services->map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name])->values()->all(),
            'existing_clusters' => $clusterNames,
            'queries' => $queries->map(fn (object $q): array => ['id' => (int) $q->id, 'text' => (string) $q->text, 'impressions_28d' => (int) $q->impressions_28d, 'clicks_28d' => (int) $q->clicks_28d])->all(),
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values()->all(),
        ], 240);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status'], 'added' => 0];
        }
        $known = $queries->pluck('text', 'id')->all();
        $taken = Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)->pluck('title')->map(fn ($t): string => SeoText::fold((string) $t))->all();
        $added = 0;
        foreach (array_slice((array) ($result['data']['items'] ?? []), 0, 10) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ids = array_values(array_unique(array_filter((array) ($item['query_ids'] ?? []), fn ($id): bool => is_int($id) && isset($known[$id]))));
            $newQueries = array_values(array_slice(array_filter(array_map(fn ($t): string => mb_substr(trim((string) $t), 0, 200), (array) ($item['new_queries'] ?? [])), fn (string $t): bool => mb_strlen($t) >= 3), 0, 5));
            if ($ids === []) {
                continue; // an opportunity must rest on at least one real query of the brand
            }
            $serviceId = is_int($item['service_id'] ?? null) && $services->has($item['service_id']) ? $item['service_id'] : null;
            $stored = $this->storeItem($brand, $site, $sitePages, $item + ['kind' => 'new', 'target_url' => null], $taken, [
                'cluster_id' => null, 'out_of_cluster' => true, 'query_ids' => $ids, 'new_queries' => $newQueries, 'service_id' => $serviceId,
                'evidence' => array_map(fn (int $id): array => ['kind' => 'query', 'value' => (string) $known[$id], 'source' => 'Search Console'], array_slice($ids, 0, 5)),
                'service' => $serviceId !== null ? (string) $services->get($serviceId) : '',
            ], $result['prompt_version_id']);
            $added += $stored ? 1 : 0;
        }

        return ['status' => 'ready', 'added' => $added];
    }

    /**
     * "Kütüphaneye ekle": a discovered opportunity becomes a (not yet approved) cluster of the shared sector + service
     * library with its real queries and the new ones as "önerilen".
     */
    public function addToLibrary(Suggestion $suggestion): Cluster
    {
        $action = (array) $suggestion->action;
        $brand = Brand::query()->find($suggestion->brand_id);
        $serviceId = $action['service_id'] ?? null;
        if (! ($action['out_of_cluster'] ?? false) || ! is_int($serviceId) || $brand?->sector_id === null) {
            throw ValidationException::withMessages(['library' => 'Kütüphaneye eklemek için önerinin bir hizmeti olmalı.']);
        }
        if (is_int($action['library_cluster_id'] ?? null) && Cluster::query()->whereKey($action['library_cluster_id'])->exists()) {
            throw ValidationException::withMessages(['library' => 'Bu fırsat zaten kütüphanede.']);
        }

        return DB::transaction(function () use ($suggestion, $action, $brand, $serviceId): Cluster {
            $free = Query::query()->whereIn('id', (array) ($action['query_ids'] ?? []))->whereNotIn('id', ClusterQuery::query()->select('query_id'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $pageType = (string) ($action['page_type'] ?? 'blog');
            $cluster = Cluster::query()->create([
                'sector_id' => $brand->sector_id, 'service_id' => $serviceId, 'name' => mb_substr((string) $suggestion->title, 0, 200),
                'intent' => in_array($pageType, ['blog', 'sss'], true) ? 'informational' : 'commercial', 'main_query_id' => $free[0] ?? null,
                'page_type' => self::CLUSTER_PAGE_TYPES[$pageType] ?? 'other', 'subtopics' => array_values(array_slice((array) ($action['outline'] ?? []), 0, 12)),
                'reasoning' => mb_substr((string) $suggestion->reason, 0, 1000), 'approved' => false, 'locked' => false,
            ]);
            $now = now();
            $members = array_map(fn (int $id): array => ['cluster_id' => $cluster->id, 'query_id' => $id, 'is_suggested' => false, 'created_at' => $now, 'updated_at' => $now], $free);
            $normalizer = app(QueryNormalizer::class);
            foreach ((array) ($action['new_queries'] ?? []) as $text) {
                $normalized = $normalizer->normalize((string) $text);
                $hash = QueryNormalizer::hash($normalized);
                if (mb_strlen($normalized) < 3 || Query::query()->where('text_hash', $hash)->exists()) {
                    continue;
                }
                $query = Query::query()->create(['text' => $normalized, 'text_hash' => $hash, 'sector_id' => $brand->sector_id, 'service_id' => $serviceId, 'assignment' => 'ai', 'is_suggested' => true]);
                $members[] = ['cluster_id' => $cluster->id, 'query_id' => $query->id, 'is_suggested' => true, 'created_at' => $now, 'updated_at' => $now];
            }
            ClusterQuery::query()->insert($members);
            $suggestion->forceFill(['action' => array_merge($action, ['library_cluster_id' => $cluster->id])])->save();

            return $cluster;
        });
    }

    /**
     * Rakipler "Yeni içerik olarak ekle": a competitor suggestion without a page of ours becomes an İçerik plan item
     * (new page of the cluster's page type, target URL from the site's URL pattern); the competitor suggestion is
     * approved and points to it. Idempotent.
     */
    public function fromCompetitor(Suggestion $competitor, User $user): Suggestion
    {
        if ($competitor->action_type !== SiteSuggestionTypes::COMPETITOR || $competitor->page_id !== null) {
            throw ValidationException::withMessages(['content' => 'Yalnız sayfası olmayan rakip önerisi yeni içerik olur.']);
        }
        $existing = is_int(data_get($competitor->action, 'content_suggestion_id')) ? Suggestion::query()->find(data_get($competitor->action, 'content_suggestion_id')) : null;
        if ($existing !== null) {
            return $existing;
        }
        $site = DigitalAsset::query()->find((int) data_get($competitor->evidence, 'website_asset_id'));
        $brand = Brand::query()->find($competitor->brand_id);
        if ($site === null || $brand === null) {
            throw ValidationException::withMessages(['content' => 'Site bulunamadı.']);
        }
        $cluster = $competitor->cluster_id !== null ? Cluster::query()->with('service.primaryName')->find($competitor->cluster_id) : null;
        $pageType = array_search((string) $cluster?->page_type, self::CLUSTER_PAGE_TYPES, true) ?: 'blog';
        $folded = SeoText::fold((string) $competitor->title);
        $sitePages = $this->sitePages($site);
        $pattern = new SiteUrlPattern($sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'path' => (string) $p->path, 'cms_type' => $p->wp_post_type])->all());
        $target = $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify((string) $competitor->title), (string) ($cluster?->service?->primaryName?->raw_label ?? ''));

        return DB::transaction(function () use ($competitor, $user, $brand, $site, $cluster, $pageType, $folded, $target): Suggestion {
            $content = Suggestion::query()->firstOrCreate(['brand_id' => $brand->id, 'fingerprint' => hash('sha256', implode('|', [$brand->id, 'content', $folded]))], [
                'channel' => 'search', 'decision_key' => 'site.content', 'material_hash' => hash('sha256', $folded),
                'title' => $competitor->title, 'reason' => $competitor->reason, 'priority' => $competitor->priority ?? 3,
                'evidence' => array_map(fn (string $url): array => ['kind' => 'url', 'value' => $url, 'source' => 'rakip'], array_slice((array) data_get($competitor->evidence, 'competitor_urls', []), 0, 5)),
                'action_type' => SiteSuggestionTypes::CONTENT, 'target_type' => 'site', 'target_id' => $site->id, 'page_id' => null, 'cluster_id' => $cluster?->id,
                'prompt_version_id' => $competitor->prompt_version_id, 'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(),
                'action' => ['site_id' => (int) $site->id, 'kind' => 'new', 'page_type' => $pageType, 'target_url' => $target,
                    'outline' => array_values(array_filter((array) ($cluster?->subtopics ?? []), 'is_string')), 'questions' => [], 'out_of_cluster' => false,
                    'from_suggestion_id' => (int) $competitor->id, 'week' => now()->format('o-\WW')],
            ]);
            $competitor->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(),
                'action' => array_merge((array) $competitor->action, ['content_suggestion_id' => (int) $content->id])])->save();
            $this->memory->recordDecision($competitor, 'onaylandı', 'yeni içerik');

            return $content;
        });
    }

    /**
     * İçerik fikirleri "AI ile üret" (blueprint §5.7): an idea row without a page becomes an İçerik plan item (title,
     * page type, outline, AI questions, target URL from the site's URL pattern; an extra idea also carries its angle,
     * target queries and the main idea's page to link to; the stored SEO analizi recipe goes along) and its article
     * is written at once. Review and the WordPress draft (ADR-064) stay in the İçerik tab. Idempotent per title.
     *
     * @return array{status: string, suggestion_id?: int, message?: string}
     */
    public function produce(ContentIdeaSubject $subject): array
    {
        if ($subject->row->page_id !== null) {
            return ['status' => 'has_page'];
        }
        $brand = $subject->brand();
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational'];
        }
        $site = $subject->site;
        $title = $subject->title();
        $folded = SeoText::fold($title);
        $pageType = array_search($subject->type(), self::CLUSTER_PAGE_TYPES, true) ?: 'blog';
        $sitePages = $this->sitePages($site);
        $pattern = new SiteUrlPattern($sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'path' => (string) $p->path, 'cms_type' => $p->wp_post_type])->all());
        $outline = $subject->idea !== null ? array_values((array) $subject->idea->outline) : array_values(array_filter((array) $subject->cluster->subtopics, 'is_string'));
        $suggestion = Suggestion::query()->firstOrCreate(['brand_id' => $brand->id, 'fingerprint' => hash('sha256', implode('|', [$brand->id, 'content', $folded]))], [
            'channel' => 'search', 'decision_key' => 'site.content', 'material_hash' => hash('sha256', $folded),
            'title' => mb_substr($title, 0, 160), 'reason' => mb_substr((string) ($subject->idea?->angle ?? $subject->cluster->user_need ?? ''), 0, 240), 'priority' => 2,
            'evidence' => [['kind' => 'cluster', 'value' => $subject->cluster->name.' · Sayfa yok', 'source' => 'İçerik fikirleri']],
            'action_type' => SiteSuggestionTypes::CONTENT, 'target_type' => 'site', 'target_id' => $site->id, 'page_id' => null, 'cluster_id' => $subject->cluster->id,
            'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => ['site_id' => (int) $site->id, 'kind' => 'new', 'page_type' => $pageType,
                'target_url' => $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify(ForbiddenTerms::forBrand($brand)->scrub($title)), (string) ($subject->cluster->service?->primaryName?->raw_label ?? '')),
                'outline' => array_slice($outline, 0, 12), 'questions' => array_slice(ClusterAudit::aiQuestions($subject->cluster, $brand), 0, 10), 'out_of_cluster' => false, 'week' => now()->format('o-\WW')],
        ]);
        $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, array_filter([
            'angle' => $subject->idea?->angle, 'target_queries' => $subject->idea !== null ? array_column((array) $subject->idea->target_queries, 'text') : null,
            'main_page_url' => $subject->mainPage()?->url, 'recipe' => data_get($subject->row->recipe, 'steps') ?: null,
            'recipe_seo' => array_filter(['seo_title' => data_get($subject->row->recipe, 'seo_title'), 'meta_description' => data_get($subject->row->recipe, 'meta_description')]) ?: null,
        ], fn (mixed $v): bool => $v !== null && $v !== []) + $subject->params())])->save();

        return ['suggestion_id' => (int) $suggestion->id] + $this->writeArticle($suggestion);
    }

    /**
     * "Taslak hazırla": the article (validated, compliance-checked) is stored on the suggestion for review. With a
     * language of the site other than the written article's, the same plan is written in that language as a
     * translation (`action.translations.{lang}`), sent with the source as linked Polylang drafts (ADR-076).
     *
     * @return array{status: string, message?: string}
     */
    public function writeArticle(Suggestion $suggestion, ?string $language = null): array
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        $brand = Brand::query()->with('customer', 'sectorCategory')->find($suggestion->brand_id);
        if ($suggestion->action_type !== SiteSuggestionTypes::CONTENT || $site === null) {
            return ['status' => 'not_applicable'];
        }
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational'];
        }
        $sitePages = $this->sitePages($site);
        $cluster = $suggestion->cluster_id !== null ? Cluster::query()->with(['mainQuery', 'clusterQueries.searchQuery'])->find($suggestion->cluster_id) : null;
        $context = $this->memory->contextFor($brand, $suggestion->page_id !== null ? [(int) $suggestion->page_id] : [], $cluster !== null ? [(int) $cluster->id] : []);
        $sourceLanguage = self::articleLanguage($suggestion, $site);
        $language = $language !== null && in_array($language, self::siteLanguages($site), true) ? $language : $sourceLanguage;
        $translation = $language !== $sourceLanguage && is_array($action['article'] ?? null);
        $terms = ForbiddenTerms::forBrand($brand);
        // Every input the writer copies loses its forbidden phrases first ("En iyi … seçerken" → "… seçerken"); repeated lines go once.
        $scrub = fn (array $lines): array => array_values(array_unique(array_filter(array_map(fn ($line): string => $terms->scrub((string) $line), $lines), fn (string $l): bool => $l !== '')));
        $text = fn (mixed $value): ?string => ($clean = $terms->scrub(trim((string) $value))) !== '' ? $clean : null;
        $questions = $scrub((array) ($action['questions'] ?? []));
        $angle = $text($action['angle'] ?? null);
        $reason = $text($suggestion->reason);
        $recipe = isset($action['recipe']) ? $scrub(array_map(fn (array $s): string => trim(($s['where'] ?? '') !== '' ? $s['where'].': '.$s['action'] : (string) ($s['action'] ?? '')), (array) $action['recipe'])) : [];
        $recipeSeo = (array) ($action['recipe_seo'] ?? []);
        $input = [
            'plan' => ['title' => $terms->scrub((string) $suggestion->title), 'page_type' => $action['page_type'] ?? 'blog', 'outline' => $scrub((array) ($action['outline'] ?? [])),
                'questions' => $questions, 'target_url' => $action['target_url'] ?? null, 'kind' => ($action['kind'] ?? null) === 'update' ? 'update' : 'new']
                + array_filter(['reason' => $reason !== $angle ? $reason : null, 'angle' => $angle, 'target_queries' => $scrub((array) ($action['target_queries'] ?? [])), 'main_page_url' => $action['main_page_url'] ?? null,
                    'recipe' => $recipe, 'seo_title' => $text($recipeSeo['seo_title'] ?? null), 'meta_description' => $text($recipeSeo['meta_description'] ?? null)],
                    fn (mixed $v): bool => $v !== null && $v !== []),
            'cluster' => $cluster !== null ? array_filter(['name' => $cluster->name, 'main_query' => $text($cluster->mainQuery?->text), 'subtopics' => $scrub(array_filter((array) $cluster->subtopics, 'is_string')),
                'queries' => $scrub($cluster->clusterQueries->filter(fn (ClusterQuery $q): bool => $q->searchQuery !== null && ! $q->searchQuery->hidden)
                    ->sortByDesc(fn (ClusterQuery $q): int => (int) $q->searchQuery->impressions)->map(fn (ClusterQuery $q): string => (string) $q->searchQuery->text)->take(30)->all()),
                // Questions already in the plan are not sent twice.
                'ai_questions' => array_values(array_diff($scrub(ClusterAudit::aiQuestions($cluster, $brand)), $questions)), 'service_areas' => ClusterAudit::serviceAreas($cluster, $brand) ?: null,
                'benchmarks' => app(ClusterBenchmarks::class)->for($cluster, (int) $brand->id) ?: null],
                fn (mixed $v): bool => $v !== null && $v !== []) : null,
            'brand' => $context['profile'], 'notes' => $context['notes'], 'standards' => $context['standards'], 'related_pages' => [...$context['pages'], ...$context['related_pages']],
            'language' => $language,
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values()->all(),
            'forbidden' => $terms->phrases(),
        ];
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        foreach ($context['pages'] as $page) {
            $evidence->addNumbersFrom(['s' => $page['summary'], 'f' => $page['facts']]);
        }
        if ($translation) {
            unset($action['translations'][$language], $action['translations_blocked'][$language]);
        } else {
            unset($action['article'], $action['article_blocked'], $action['article_blocked_draft']);
            $action['language'] = $language;
        }
        // At most two writes: when the first one breaks a sector rule, the second gets the offending phrases to rewrite.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $outcome = $this->writeOnce($input, $evidence, $action, $cluster, $brand, $language, 'suggestion-'.$suggestion->id.($translation ? '-'.$language : ''));
            if ($outcome['status'] !== 'blocked' || $outcome['violations'] === [] || $attempt === 2) {
                break;
            }
            $input['fix'] = ContentComplianceGate::forPrompt($outcome['violations']);
        }
        if ($outcome['status'] === 'invalid' || $outcome['status'] !== 'ready' && ! isset($outcome['article'])) {
            return array_intersect_key($outcome, ['status' => 1, 'message' => 1]);
        }
        $article = $outcome['article'];
        if ($translation) {
            $key = $outcome['status'] === 'blocked' ? 'translations_blocked' : 'translations';
            $action[$key] = array_merge((array) ($action[$key] ?? []), [$language => $outcome['status'] === 'blocked' ? $outcome['message'] : $article]);
            $suggestion->forceFill(['action' => $action])->save();

            return $outcome['status'] === 'blocked' ? ['status' => 'blocked', 'message' => $outcome['message']] : ['status' => 'ready'];
        }
        if ($outcome['status'] === 'blocked') {
            // Kept to read and fix by hand; never sent while blocked.
            $suggestion->forceFill(['action' => $action + ['article_blocked' => $outcome['message'], 'article_blocked_draft' => $article]])->save();

            return ['status' => 'blocked', 'message' => $outcome['message']];
        }
        $warnings = $terms->warnings(implode(' . ', [$article['title'], $article['meta_title'], $article['meta_description'], strip_tags($article['html'])]));
        $action['article_warnings'] = $warnings !== [] ? 'Uyarı (yasaklı ifade, uyar): «'.implode('», «', $warnings).'»' : null;
        $action['article_seo'] = ArticleSeoCheck::check($article, $input['cluster']['main_query'] ?? null, (array) ($input['cluster']['service_areas'] ?? []), SiteScope::origin($site));
        $others = array_values(array_diff(self::siteLanguages($site), [$language]));
        $suggestion->forceFill(['action' => array_merge($action, ['article' => $article,
            'article_note' => $others !== [] ? 'Sitede başka dil de var ('.implode(', ', $others).'): Genel işler › içerik kutusunda o dilde de yazdırılabilir.' : null])])->save();

        return ['status' => 'ready'];
    }

    /** The language the article of this idea is (or will be) written in: the operator's pick, else the site's main language. */
    public static function articleLanguage(Suggestion $suggestion, DigitalAsset $site): string
    {
        $picked = data_get($suggestion->action, 'language');

        return is_string($picked) && $picked !== '' ? $picked : (SiteScope::primaryLanguage($site) ?? 'tr');
    }

    /** @return list<string> the languages the site's pages use (its main language first), plus the asset's own setting */
    public static function siteLanguages(DigitalAsset $site): array
    {
        $primary = SiteScope::primaryLanguage($site) ?? 'tr';
        $declared = array_map(fn ($l): string => strtolower(substr((string) $l, 0, 2)), array_filter((array) ($site->languages ?? []), 'is_string'));

        return array_values(array_unique([$primary, ...SiteScope::languages($site), ...$declared]));
    }

    /**
     * One write: the agent's article, grounded and checked (sector rules, copy check).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $action
     * @return array{status: string, message?: string, article?: array<string, mixed>, violations: list<array<string, mixed>>}
     */
    private function writeOnce(array $input, SiteEvidence $evidence, array $action, ?Cluster $cluster, Brand $brand, string $language, string $reference): array
    {
        $result = $this->ai->run(new WriteArticleAgent, $input, 600);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status'], 'violations' => []];
        }
        $data = $result['data'];
        $html = $this->groundedHtml((string) ($data['html'] ?? ''), $evidence);
        $main = (string) ($action['main_page_url'] ?? '');
        if ($html !== '' && $main !== '' && ! str_contains($html, 'href="'.$main.'"') && ! str_contains($html, "href='".$main."'")) {
            // An extra idea always links to its main idea's page (blueprint §4.1).
            $html .= '<p>İlgili: <a href="'.e($main).'">'.e((string) ($cluster?->name ?? $main)).'</a></p>';
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($html === '' || mb_strlen($title) < 5 || ! $evidence->grounded($title)) {
            return ['status' => 'invalid', 'message' => 'AI makalesi doğrulanamadı.', 'violations' => []];
        }
        $article = [
            'title' => mb_substr($title, 0, 200), 'slug' => SeoText::slugify((string) ($data['slug'] ?? $title)) ?: SeoText::slugify($title), 'html' => $html,
            // The approved SEO analysis title / description win over the writer's own.
            'meta_title' => mb_substr(trim((string) ($input['plan']['seo_title'] ?? $data['meta_title'] ?? '')), 0, 120),
            'meta_description' => mb_substr(trim((string) ($input['plan']['meta_description'] ?? $data['meta_description'] ?? '')), 0, 320),
            'excerpt' => mb_substr(trim((string) ($data['excerpt'] ?? '')), 0, 300), 'language' => $language,
            // A new article is always a post (operator decision 2026-10-03); only rewriting an existing non-blog page stays a page.
            'post_type' => ($action['kind'] ?? null) === 'update' && ($action['page_type'] ?? 'blog') !== 'blog' ? 'page' : 'post', 'reference' => $reference,
        ];
        $violations = ContentComplianceGate::blocking(app(ContentComplianceGate::class)->violations($brand, ArticleDraft::fromArray($article)));
        if ($violations !== []) {
            return ['status' => 'blocked', 'message' => ContentComplianceGate::summary($violations), 'article' => $article, 'violations' => $violations];
        }
        $copy = $cluster !== null ? CopyCheck::check($html, ClusterBenchmarks::otherBrandTexts((int) $cluster->id, (int) $brand->id)) : ['ok' => true];
        if (! $copy['ok']) {
            return ['status' => 'blocked', 'message' => CopyCheck::message($copy), 'article' => $article, 'violations' => []];
        }

        return ['status' => 'ready', 'article' => $article, 'violations' => []];
    }

    /**
     * Admin approval: the prepared article goes to WordPress as a draft (existing rich draft path, undoable), with its
     * written translations as linked drafts (ADR-076). A translation written after the source was sent goes on its own.
     */
    public function sendDraft(Suggestion $suggestion, User $user): int
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        if (! is_array($action['article'] ?? null) || $site === null) {
            throw ValidationException::withMessages(['write' => 'Önce "Taslak hazırla".']);
        }
        $sent = array_values((array) ($action['sent_languages'] ?? []));
        $pending = array_filter((array) ($action['translations'] ?? []), fn ($article, $lang): bool => is_array($article) && ! in_array($lang, $sent, true), ARRAY_FILTER_USE_BOTH);
        $drafts = array_map(fn (array $article): ArticleDraft => ArticleDraft::fromArray($article), array_values($pending));
        if (! isset($action['article_write_id'])) {
            $source = ArticleDraft::fromArray($action['article']);
            $write = app(ExternalWriteService::class)->requestArticleDrafts($user, $site, $source, $drafts);
            $action = array_merge($action, ['article_write_id' => $write->id, 'sent_languages' => [$source->language ?? self::articleLanguage($suggestion, $site), ...array_keys($pending)]]);
        } elseif ($drafts !== []) {
            $write = app(ExternalWriteService::class)->requestArticleDrafts($user, $site, $drafts[0], array_slice($drafts, 1));
            $action = array_merge($action, ['sent_languages' => [...$sent, ...array_keys($pending)],
                'translation_write_ids' => [...(array) ($action['translation_write_ids'] ?? []), (int) $write->id]]);
        } else {
            throw ValidationException::withMessages(['write' => 'Bu yazı zaten gönderildi; gönderilecek yeni dil yok.']);
        }
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'action' => $action])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı', 'WordPress taslağı');

        return (int) $write->id;
    }

    /**
     * @param  Collection<int, Page>  $sitePages
     * @param  array<string, mixed>  $item
     * @param  list<string>  $taken  folded titles already planned
     * @param  array<string, mixed>  $extra
     */
    private function storeItem(Brand $brand, DigitalAsset $site, Collection $sitePages, array $item, array &$taken, array $extra, ?int $promptVersionId): bool
    {
        $compliance = BriefCompliance::forBrand($brand);
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        $title = trim((string) ($item['title'] ?? ''));
        $folded = SeoText::fold($title);
        $pageType = in_array($item['page_type'] ?? null, self::PAGE_TYPES, true) ? $item['page_type'] : 'blog';
        if (mb_strlen($title) < 5 || in_array($folded, $taken, true) || ! $compliance->isCompliant($title) || ! $evidence->grounded($title)) {
            return false;
        }
        $lines = fn (mixed $list, int $max): array => array_values(array_slice($compliance->filter(array_values(array_filter(array_map(fn ($l): string => mb_substr(trim((string) $l), 0, 200), (array) $list),
            fn (string $l): bool => $l !== '' && $evidence->grounded($l)))), 0, $max));
        $kind = ($item['kind'] ?? 'new') === 'update' ? 'update' : 'new';
        $target = null;
        $pageId = null;
        if ($kind === 'update') {
            $page = $sitePages->first(fn (Page $p): bool => SeoText::urlKey((string) $p->url) === SeoText::urlKey((string) ($item['target_url'] ?? '')));
            if ($page === null) {
                return false; // an update must name a real page of the site
            }
            [$target, $pageId] = [(string) $page->url, (int) $page->id];
        } else {
            $pattern = new SiteUrlPattern($sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'path' => (string) $p->path, 'cms_type' => $p->wp_post_type])->all());
            $target = $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify($title), (string) ($extra['service'] ?? ''));
        }
        $reason = trim((string) ($item['reason'] ?? ''));
        $fingerprint = hash('sha256', implode('|', [$brand->id, 'content', $folded]));
        if (Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->exists()) {
            return false;
        }
        Suggestion::query()->create([
            'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => $fingerprint, 'material_hash' => hash('sha256', $folded),
            'title' => mb_substr($title, 0, 160), 'reason' => mb_substr($evidence->grounded($reason) ? $reason : '', 0, 240), 'priority' => 3,
            'evidence' => $extra['evidence'] ?? [['kind' => 'none', 'value' => 'veri yok', 'source' => '']],
            'action_type' => SiteSuggestionTypes::CONTENT, 'target_type' => $pageId !== null ? 'page' : 'site', 'target_id' => $pageId ?? $site->id,
            'page_id' => $pageId, 'cluster_id' => $extra['cluster_id'] ?? null, 'prompt_version_id' => $promptVersionId, 'status' => Suggestion::OPEN,
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => array_filter([
                'site_id' => (int) $site->id, 'kind' => $kind, 'page_type' => $pageType, 'target_url' => $target,
                'outline' => $lines($item['outline'] ?? [], 12), 'questions' => $lines($item['questions'] ?? [], 10),
                'out_of_cluster' => (bool) ($extra['out_of_cluster'] ?? false), 'query_ids' => $extra['query_ids'] ?? null, 'new_queries' => $extra['new_queries'] ?? null,
                'service_id' => $extra['service_id'] ?? null, 'week' => now()->format('o-\WW'),
                'language' => $extra['language'] ?? null, 'angle' => $extra['angle'] ?? null,
            ], fn (mixed $v): bool => $v !== null),
        ]);
        $taken[] = $folded;

        return true;
    }

    /**
     * The real searches of each cluster, most impressions first (what the article must answer).
     *
     * @param  list<int>  $clusterIds
     * @return array<int, list<string>>
     */
    private function clusterQueries(array $clusterIds, int $per = 8): array
    {
        $out = [];
        DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds ?: [0])->where('q.hidden', false)
            ->orderBy('cq.cluster_id')->orderByDesc('q.impressions')->orderBy('q.id')->get(['cq.cluster_id', 'q.text'])
            ->each(function (object $row) use (&$out, $per): void {
                if (count($out[(int) $row->cluster_id] ?? []) < $per) {
                    $out[(int) $row->cluster_id][] = (string) $row->text;
                }
            });

        return $out;
    }

    /** @return Collection<int, Page> */
    private function sitePages(DigitalAsset $site): Collection
    {
        return Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->orderBy('path')->limit(300)->get(['id', 'url', 'path', 'title', 'category', 'wp_post_type']);
    }

    /** Article HTML with scripts removed, links only to site pages, and blocks with invented numbers dropped. */
    private function groundedHtml(string $html, SiteEvidence $evidence): string
    {
        $html = (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', fn (array $m): string => $evidence->knowsUrl($m[1]) ? $m[0] : $m[2], $html);
        $html = (string) preg_replace_callback('#<(p|li|h[2-6]|td)\b[^>]*>.*?</\1>#is', fn (array $m): string => $evidence->grounded(strip_tags($m[0])) ? $m[0] : '', $html);

        return trim(strip_tags($html)) === '' ? '' : trim($html);
    }
}
