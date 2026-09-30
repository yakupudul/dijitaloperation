<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\UrlAnalysisAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandClusterSerp;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CompetitorPage;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Compliance\BriefCompliance;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Competitors\CompetitorPageStore;
use App\Services\Site\Competitors\CompetitorRefresher;
use Illuminate\Support\Facades\DB;

/**
 * URL analizi: per URL one AI call (`site.url_analysis`) over a data pack — brand info + main services, areas /
 * language, target audience / markets, the URL's clusters, page content + SEO fields, Search Console / GA4 28 days,
 * related pages' summaries, applicable standards, decisions and selected competitor examples of the URL's clusters.
 * Output → `suggestions` (channel search, target page) after validation: every URL must be a site page (or a cited
 * competitor page), every number must be in the pack, every quote must be in the page; no evidence → "veri yok".
 */
final class UrlAnalyzer
{
    public const int MAX_PAGES = 20;

    public const int MAX_CONTENT = 12000;

    public const int MAX_SUGGESTIONS = 30;

    /** Competitor examples per cluster (top ranked, fetched pages). */
    public const int COMPETITOR_EXAMPLES = 3;

    public function __construct(
        private readonly SiteAi $ai,
        private readonly SiteMetrics $metrics,
        private readonly BrandMemoryService $memory,
        private readonly ScopedStandards $standards,
    ) {}

    /**
     * @param  list<int>  $pageIds
     * @return array{status: string, pages: int, suggestions: int}
     */
    public function analyze(DigitalAsset $site, array $pageIds): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'pages' => 0, 'suggestions' => 0];
        }
        $pages = Page::query()->where('website_asset_id', $site->id)->whereIn('id', $pageIds)->orderBy('id')->limit(self::MAX_PAGES)->get();
        if ($pages->isEmpty()) {
            return ['status' => 'no_pages', 'pages' => 0, 'suggestions' => 0];
        }
        $done = 0;
        $stored = 0;
        foreach ($pages as $page) {
            [$pack, $evidence, $clusterIds] = $this->pack($brand, $site, $page);
            $result = $this->ai->run(new UrlAnalysisAgent, $pack, 240);
            if ($result['status'] !== 'ready') {
                return ['status' => $result['status'], 'pages' => $done, 'suggestions' => $stored];
            }
            $stored += $this->store($brand, $page, (array) ($result['data']['suggestions'] ?? []), $evidence, $clusterIds, $result['prompt_version_id']);
            Page::query()->whereKey($page->id)->update(['analyzed_at' => now()]);
            $done++;
        }

        return ['status' => 'ready', 'pages' => $done, 'suggestions' => $stored];
    }

    /**
     * The data pack of one URL and its grounding set.
     *
     * @return array{0: array<string, mixed>, 1: SiteEvidence, 2: list<int>}
     */
    public function pack(Brand $brand, DigitalAsset $site, Page $page): array
    {
        $clusters = BrandClusterPage::query()->with('cluster.mainQuery')->where('brand_id', $brand->id)
            ->where(fn ($q) => $q->where('page_id', $page->id)->orWhereJsonContains('extra_page_ids', (int) $page->id))->orderBy('id')->get();
        $clusterIds = $clusters->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all();
        $context = $this->memory->contextFor($brand, [(int) $page->id], $clusterIds);
        // Lazy summaries of the pages the pack refers to.
        $related = array_column($context['related_pages'], 'id');
        if (collect([...$context['pages'], ...$context['related_pages']])->contains(fn (array $p): bool => $p['summary'] === null)) {
            $this->memory->summarize($brand, [(int) $page->id, ...$related]);
            $context = $this->memory->contextFor($brand, [(int) $page->id], $clusterIds);
        }
        $gsc = $this->metrics->pageTotal($brand, $site, (string) $page->url);
        $queries = $gsc !== null ? $this->metrics->pageQueries($brand, (string) $page->url) : [];
        $ga4 = $this->metrics->ga4Landing($brand, (string) $page->url);
        $sitePages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->orderBy('path')->limit(300)->get(['id', 'url', 'title', 'category']);
        $content = mb_substr((string) $page->content_text, 0, self::MAX_CONTENT);
        $competitors = $this->competitorExamples($brand, $site, $clusterIds);
        $pack = [
            'brand' => [
                'name' => $brand->name, 'sector' => $brand->sectorCategory?->name,
                'main_services' => SiteScope::offerings($brand)->map(fn (BrandOffering $o): array => ['name' => $o->displayName(), 'priority' => (string) ($o->priority ?? 'secondary')])->values()->all(),
                'areas' => SiteScope::areas($brand)->map(fn (BrandServiceArea $a): array => ['name' => $a->displayName(), 'physical_branch' => (bool) $a->physical_branch])->values()->all(),
                'languages' => SiteScope::languages($site),
                'audience' => filled($brand->audience) ? mb_substr((string) $brand->audience, 0, 600) : null,
                'target_markets' => array_values(array_filter((array) $brand->target_markets, fn ($m): bool => is_string($m) && trim($m) !== '')) ?: null,
                'notes' => $context['notes'],
            ],
            'page' => [
                'id' => (int) $page->id, 'url' => (string) $page->url, 'category' => $page->category, 'language' => $page->language,
                'title' => $page->title, 'meta_description' => $page->meta_description, 'h1' => $page->h1, 'canonical' => $page->canonical,
                'headings' => array_values((array) $page->headings), 'word_count' => (int) $page->word_count, 'indexable' => (bool) $page->is_indexable,
                'content' => $content,
            ],
            'clusters' => $clusters->map(fn (BrandClusterPage $row): array => [
                'cluster_id' => (int) $row->cluster_id, 'name' => (string) $row->cluster?->name, 'intent' => (string) $row->cluster?->intent,
                'main_query' => (string) ($row->cluster?->mainQuery?->text ?? ''), 'target_query' => $row->target_query, 'state' => $row->state,
                'subtopics' => array_values((array) $row->cluster?->subtopics),
            ])->values()->all(),
            'search_console_28d' => $gsc === null ? 'veri yok' : ['clicks' => $gsc['clicks'], 'impressions' => $gsc['impressions'], 'position' => $gsc['position'], 'top_queries' => $queries],
            'ga4_28d' => $ga4 ?? 'veri yok',
            'related_pages' => $context['related_pages'],
            'page_summary' => $context['pages'][0]['summary'] ?? null,
            'standards' => ['checks' => $this->standards->pageChecks($site, $page, $brand), 'scoped' => $context['standards']],
            'decisions' => $context['decisions'],
            'competitor_examples' => $competitors === [] ? 'veri yok' : $competitors,
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title, 'category' => $p->category])->values()->all(),
            'suggestion_types' => SiteSuggestionTypes::ANALYSIS,
        ];
        $evidence = new SiteEvidence(
            [...$sitePages->pluck('url')->map(fn ($u): string => (string) $u)->push((string) $page->url)->all(), ...collect($competitors)->flatMap(fn (array $c): array => array_column($c['pages'], 'url'))->all()],
            [(int) $page->word_count],
            [(string) $page->title, (string) $page->meta_description, (string) $page->h1, $content, collect((array) $page->headings)->pluck('text')->implode(' '),
                ...array_map(fn (array $q): string => $q['query'], $queries), ...array_map(fn (array $c): string => $c['main_query'].' '.implode(' ', $c['subtopics']), $pack['clusters'])],
        );
        $evidence->addNumbersFrom(['gsc' => $gsc, 'queries' => $queries, 'ga4' => $ga4]);

        return [$pack, $evidence, $clusterIds];
    }

    /**
     * Selected competitor examples of the URL's clusters: per cluster the need the analysis found and the top ranked
     * fetched competitor pages (URL, title, H2 headings).
     *
     * @param  list<int>  $clusterIds
     * @return list<array{cluster_id: int, query: string, need: ?string, pages: list<array{rank: int, url: string, title: ?string, headings: list<string>}>}>
     */
    public function competitorExamples(Brand $brand, DigitalAsset $site, array $clusterIds): array
    {
        if ($clusterIds === []) {
            return [];
        }
        $out = [];
        $serps = BrandClusterSerp::query()->where('brand_id', $brand->id)->where('website_asset_id', $site->id)->whereIn('cluster_id', $clusterIds)->orderBy('cluster_id')->get();
        foreach ($serps as $serp) {
            $results = array_values(array_filter((array) $serp->results, fn ($r): bool => is_array($r) && in_array($r['class'] ?? null, CompetitorRefresher::PAGE_CLASSES, true)));
            usort($results, fn (array $a, array $b): int => (int) ($a['rank'] ?? 99) <=> (int) ($b['rank'] ?? 99));
            $fetched = CompetitorPage::query()->whereIn('url_hash', array_map(fn (array $r): string => CompetitorPageStore::hash((string) $r['url']), $results))
                ->where('status', CompetitorPage::OK)->get(['url_hash', 'title', 'headings'])->keyBy('url_hash');
            $pages = [];
            foreach ($results as $result) {
                $competitor = $fetched->get(CompetitorPageStore::hash((string) $result['url']));
                if ($competitor === null) {
                    continue;
                }
                $pages[] = ['rank' => (int) $result['rank'], 'url' => (string) $result['url'], 'title' => $competitor->title,
                    'headings' => collect((array) $competitor->headings)->filter(fn ($h): bool => is_array($h) && (int) ($h['level'] ?? 0) === 2)->pluck('text')->take(12)->values()->all()];
                if (count($pages) >= self::COMPETITOR_EXAMPLES) {
                    break;
                }
            }
            if ($pages !== []) {
                $out[] = ['cluster_id' => (int) $serp->cluster_id, 'query' => (string) $serp->query, 'need' => $serp->analysis['need'] ?? null, 'pages' => $pages];
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $rows
     * @param  list<int>  $clusterIds
     */
    private function store(Brand $brand, Page $page, array $rows, SiteEvidence $evidence, array $clusterIds, ?int $promptVersionId): int
    {
        $seen = [];
        $stored = 0;
        $compliance = BriefCompliance::forBrand($brand);
        foreach (array_slice($rows, 0, self::MAX_SUGGESTIONS) as $row) {
            $clean = is_array($row) ? $this->validated($row, $evidence, $clusterIds) : null;
            // Sector gate: advice the sector pack forbids (health: price emphasis, guarantees…) is not shown.
            if ($clean === null || ! $compliance->isCompliant($clean['title'])) {
                continue;
            }
            $fingerprint = hash('sha256', implode('|', [$brand->id, 'site', $page->id, $clean['type'], SeoText::fold($clean['title'])]));
            $seen[] = $fingerprint;
            $existing = Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->first();
            if ($existing !== null && ! in_array($existing->status, [Suggestion::OPEN, Suggestion::RECHECK], true)) {
                continue; // decided (approved / applied / dismissed) suggestions are never reopened
            }
            $values = [
                'channel' => 'search', 'decision_key' => 'site.'.$clean['type'], 'material_hash' => $page->content_hash ?? hash('sha256', ''),
                'title' => $clean['title'], 'reason' => $clean['reason'], 'priority' => $clean['priority'], 'evidence' => $clean['evidence'],
                'action_type' => $clean['type'], 'target_type' => 'page', 'target_id' => $page->id, 'page_id' => $page->id, 'cluster_id' => $clean['cluster_id'],
                'prompt_version_id' => $promptVersionId, 'status' => Suggestion::OPEN, 'last_seen_at' => now(),
                'action' => array_merge((array) ($existing?->action ?? []), ['site_id' => (int) $page->website_asset_id]),
            ];
            if ($existing !== null) {
                $existing->forceFill($values)->save();
            } else {
                Suggestion::query()->create($values + ['brand_id' => $brand->id, 'fingerprint' => $fingerprint, 'first_seen_at' => now()]);
            }
            $stored++;
        }
        // Open suggestions of this URL the new analysis did not repeat are superseded.
        Suggestion::query()->where('brand_id', $brand->id)->where('page_id', $page->id)->whereIn('action_type', array_keys(SiteSuggestionTypes::ANALYSIS))
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])->whereNotIn('fingerprint', $seen ?: [''])->delete();

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<int>  $clusterIds
     * @return array{type: string, title: string, reason: string, priority: int, cluster_id: ?int, evidence: list<array{kind: string, value: string, source: string}>}|null
     */
    public function validated(array $row, SiteEvidence $evidence, array $clusterIds): ?array
    {
        $type = (string) ($row['type'] ?? '');
        $title = trim((string) ($row['title'] ?? ''));
        $reason = trim((string) ($row['reason'] ?? ''));
        if (! array_key_exists($type, SiteSuggestionTypes::ANALYSIS) || mb_strlen($title) < 5) {
            return null;
        }
        // An invented URL or number anywhere in the visible text drops the suggestion.
        if (! $evidence->grounded($title) || ! $evidence->grounded($reason)) {
            return null;
        }
        $items = [];
        foreach (array_slice((array) ($row['evidence'] ?? []), 0, 6) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            $value = trim((string) ($item['value'] ?? ''));
            $ok = match ($kind) {
                'quote' => $evidence->knowsQuote($value),
                'number' => $value !== '' && $evidence->knowsNumber(ltrim($value, '%')),
                'url' => $evidence->knowsUrl($value),
                default => false,
            };
            if ($ok) {
                $items[] = ['kind' => $kind, 'value' => mb_substr($value, 0, 300), 'source' => mb_substr(trim((string) ($item['source'] ?? '')), 0, 80)];
            }
        }
        if ($items === []) {
            $items = [['kind' => 'none', 'value' => 'veri yok', 'source' => '']];
        }
        $cluster = is_int($row['cluster_id'] ?? null) && in_array($row['cluster_id'], $clusterIds, true) ? $row['cluster_id'] : null;

        return [
            'type' => $type, 'title' => mb_substr($title, 0, 160), 'reason' => mb_substr($reason, 0, 240),
            'priority' => max(1, min(5, (int) ($row['priority'] ?? 3))), 'cluster_id' => $cluster, 'evidence' => $items,
        ];
    }

    /** Open (actionable) website suggestions of a brand, for the screen. */
    public static function openCount(Brand $brand): int
    {
        return (int) DB::table('suggestions')->where('brand_id', $brand->id)->where('channel', 'search')->where('status', Suggestion::OPEN)->count();
    }
}
