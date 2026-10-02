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
use App\Services\Queries\QueryNormalizer;
use App\Services\SeoTasks\SeoText;
use App\Services\SeoTasks\SiteUrlPattern;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    private const array URL_TYPES = ['hizmet' => 'service', 'blog' => 'guide', 'sss' => 'faq', 'lokasyon' => 'location'];

    private const array CLUSTER_PAGE_TYPES = ['hizmet' => 'service', 'blog' => 'guide', 'sss' => 'faq', 'lokasyon' => 'location'];

    private const array MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public function __construct(
        private readonly SiteAi $ai,
        private readonly BrandMemoryService $memory,
    ) {}

    /**
     * @param  Collection<int, BrandClusterPage>|null  $only  "Konu üret": just these rows, one item
     * @return array{status: string, added: int}
     */
    public function weekly(DigitalAsset $site, ?Collection $only = null): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'added' => 0];
        }
        $capacity = $only !== null ? 1 : max(1, min(20, (int) ($brand->weekly_content_capacity ?? 4)));
        $gaps = $only ?? BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName', 'page:id,url'])->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->whereIn('state', ['no_page', 'thin_coverage'])->limit(60)->get();
        $improvable = $only !== null ? collect() : BrandClusterPage::query()->with('page:id,url,title')->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->whereIn('state', ['weak_performance', 'thin_coverage'])->whereNotNull('page_id')->limit(20)->get();
        $previous = Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where('created_at', '>=', now()->subWeeks(8))->orderByDesc('id')->limit(60)->get(['title', 'status', 'action']);
        $sitePages = $this->sitePages($site);
        $result = $this->ai->run(new WeeklyContentAgent, [
            'brand' => $this->memory->contextFor($brand, [], $gaps->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all())['profile'],
            'capacity' => $capacity,
            'month' => self::MONTHS[(int) now()->month].' '.now()->year,
            'clusters' => $gaps->map(fn (BrandClusterPage $row): array => [
                'cluster_id' => (int) $row->cluster_id, 'name' => (string) $row->cluster?->name, 'service' => (string) ($row->cluster?->service?->primaryName?->raw_label ?? ''),
                'intent' => (string) $row->cluster?->intent, 'page_type' => (string) $row->cluster?->page_type, 'main_query' => (string) ($row->cluster?->mainQuery?->text ?? ''),
                'target_query' => $row->target_query, 'state' => $row->stateLabel(), 'page_url' => $row->page?->url, 'subtopics' => array_values((array) $row->cluster?->subtopics),
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
        foreach (array_slice((array) ($result['data']['items'] ?? []), 0, $capacity) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $clusterId = is_int($item['cluster_id'] ?? null) && $clusters->has($item['cluster_id']) ? $item['cluster_id'] : null;
            $row = $clusterId !== null ? $clusters->get($clusterId) : null;
            $stored = $this->storeItem($brand, $site, $sitePages, $item, $taken, [
                'cluster_id' => $clusterId, 'out_of_cluster' => false,
                'evidence' => $row !== null ? [['kind' => 'cluster', 'value' => $row->cluster?->name.' · '.$row->stateLabel(), 'source' => 'küme']] : null,
                'service' => (string) ($row?->cluster?->service?->primaryName?->raw_label ?? ''),
            ], $result['prompt_version_id']);
            $added += $stored ? 1 : 0;
        }

        return ['status' => 'ready', 'added' => $added];
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
            ClusterQuery::insertExisting($members);
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
                'target_url' => $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify($title), (string) ($subject->cluster->service?->primaryName?->raw_label ?? '')),
                'outline' => array_slice($outline, 0, 12), 'questions' => array_slice(ClusterAudit::aiQuestions($subject->cluster, $brand), 0, 10), 'out_of_cluster' => false, 'week' => now()->format('o-\WW')],
        ]);
        $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, array_filter([
            'angle' => $subject->idea?->angle, 'target_queries' => $subject->idea !== null ? array_column((array) $subject->idea->target_queries, 'text') : null,
            'main_page_url' => $subject->mainPage()?->url, 'recipe' => data_get($subject->row->recipe, 'steps') ?: null,
        ], fn (mixed $v): bool => $v !== null && $v !== []) + $subject->params())])->save();

        return ['suggestion_id' => (int) $suggestion->id] + $this->writeArticle($suggestion);
    }

    /** "Taslak hazırla": the article (validated, compliance-checked) is stored on the suggestion for review. @return array{status: string, message?: string} */
    public function writeArticle(Suggestion $suggestion): array
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
        $language = SiteScope::primaryLanguage($site) ?? 'tr';
        $terms = ForbiddenTerms::forBrand($brand);
        // Inputs the writer copies (questions, outline) lose their forbidden phrases first ("En iyi … seçerken" → "… seçerken").
        $scrub = fn (array $lines): array => array_values(array_filter(array_map(fn ($line): string => $terms->scrub((string) $line), $lines), fn (string $l): bool => $l !== ''));
        $input = [
            'plan' => ['title' => $terms->scrub((string) $suggestion->title), 'reason' => $suggestion->reason, 'page_type' => $action['page_type'] ?? 'blog', 'outline' => $scrub((array) ($action['outline'] ?? [])),
                'questions' => $scrub((array) ($action['questions'] ?? [])), 'target_url' => $action['target_url'] ?? null, 'kind' => $action['kind'] ?? 'new']
                + array_filter(['angle' => $action['angle'] ?? null, 'target_queries' => $action['target_queries'] ?? null, 'main_page_url' => $action['main_page_url'] ?? null,
                    'recipe' => isset($action['recipe']) ? array_map(fn (array $s): string => trim(($s['where'] ?? '') !== '' ? $s['where'].': '.$s['action'] : (string) ($s['action'] ?? '')), (array) $action['recipe']) : null]),
            'cluster' => $cluster !== null ? array_filter(['name' => $cluster->name, 'main_query' => $cluster->mainQuery?->text, 'subtopics' => $cluster->subtopics,
                'queries' => $cluster->clusterQueries->map(fn (ClusterQuery $q): string => (string) $q->searchQuery?->text)->filter()->take(30)->values()->all(),
                'ai_questions' => $scrub(ClusterAudit::aiQuestions($cluster, $brand)), 'service_areas' => ClusterAudit::serviceAreas($cluster, $brand) ?: null,
                'benchmarks' => app(ClusterBenchmarks::class)->for($cluster, (int) $brand->id) ?: null],
                fn (mixed $v): bool => $v !== null) : null,
            'brand' => $context['profile'], 'notes' => $context['notes'], 'standards' => $context['standards'], 'related_pages' => [...$context['pages'], ...$context['related_pages']],
            'language' => $language,
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values()->all(),
            'forbidden' => $terms->phrases(),
        ];
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        foreach ($context['pages'] as $page) {
            $evidence->addNumbersFrom(['s' => $page['summary'], 'f' => $page['facts']]);
        }
        unset($action['article'], $action['article_blocked'], $action['article_blocked_draft']);
        // At most two writes: when the first one breaks a sector rule, the second gets the offending phrases to rewrite.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $outcome = $this->writeOnce($input, $evidence, $action, $cluster, $brand, $language, 'suggestion-'.$suggestion->id);
            if ($outcome['status'] !== 'blocked' || $outcome['violations'] === [] || $attempt === 2) {
                break;
            }
            $input['fix'] = ContentComplianceGate::forPrompt($outcome['violations']);
        }
        if ($outcome['status'] === 'invalid' || $outcome['status'] !== 'ready' && ! isset($outcome['article'])) {
            return array_intersect_key($outcome, ['status' => 1, 'message' => 1]);
        }
        $article = $outcome['article'];
        if ($outcome['status'] === 'blocked') {
            // Kept to read and fix by hand; never sent while blocked.
            $suggestion->forceFill(['action' => $action + ['article_blocked' => $outcome['message'], 'article_blocked_draft' => $article]])->save();

            return ['status' => 'blocked', 'message' => $outcome['message']];
        }
        $warnings = $terms->warnings(implode(' . ', [$article['title'], $article['meta_title'], $article['meta_description'], strip_tags($article['html'])]));
        $action['article_warnings'] = $warnings !== [] ? 'Uyarı (yasaklı ifade, uyar): «'.implode('», «', $warnings).'»' : null;
        $others = array_values(array_diff(SiteScope::languages($site), [$language]));
        $suggestion->forceFill(['action' => $action + ['article' => $article, 'article_note' => $others !== [] ? 'Diğer diller ('.implode(', ', $others).') atlandı: çeviri aracı yok.' : null]])->save();

        return ['status' => 'ready'];
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
            'meta_title' => mb_substr(trim((string) ($data['meta_title'] ?? '')), 0, 120), 'meta_description' => mb_substr(trim((string) ($data['meta_description'] ?? '')), 0, 320),
            'excerpt' => mb_substr(trim((string) ($data['excerpt'] ?? '')), 0, 300), 'language' => $language,
            'post_type' => ($action['page_type'] ?? 'blog') === 'blog' ? 'post' : 'page', 'reference' => $reference,
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

    /** Admin approval: the prepared article goes to WordPress as a draft (existing rich draft path, undoable). */
    public function sendDraft(Suggestion $suggestion, User $user): int
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        if (! is_array($action['article'] ?? null) || $site === null) {
            throw ValidationException::withMessages(['write' => 'Önce "Taslak hazırla".']);
        }
        $write = app(ExternalWriteService::class)->requestArticleDrafts($user, $site, ArticleDraft::fromArray($action['article']));
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(),
            'action' => array_merge($action, ['article_write_id' => $write->id])])->save();
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
            ], fn (mixed $v): bool => $v !== null),
        ]);
        $taken[] = $folded;

        return true;
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
