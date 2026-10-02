<?php

namespace App\Services\Site\Competitors;

use App\Ai\Agents\Site\CompetitorAnalyzeAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandClusterSerp;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\CompetitorPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\Intel\SerpResults;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rakipler "Analiz et" for one brand cluster on one site: our target page (brand_cluster_pages → pages; else "sayfa
 * yok") + the fetched competitor pages → ONE AI call (`competitors.analyze`). The answer is validated before it is
 * shown: a suggestion must cite ≥ 2 competitor URLs of the input, or be a gap while we have no page; sector compliance
 * rules drop offending suggestions. Suggestions go to the one `suggestions` table (channel search, type rakip) with the
 * competitor URLs as evidence; an unacted open suggestion the new analysis no longer makes is removed.
 */
final class CompetitorAnalyzer
{
    public const string TYPE = 'rakip';

    public const int MIN_CITATIONS = 2;

    private const int MAX_SUGGESTIONS = 15;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly SectorPackRegistry $packs,
        private readonly ComplianceChecker $checker,
    ) {}

    public static function statusKey(int $serpId): string
    {
        return 'site:competitors:analyze:'.$serpId;
    }

    public static function markRunning(int $serpId): void
    {
        Cache::put(self::statusKey($serpId), ['status' => 'running'], now()->addDay());
    }

    /** @return array{status: string, suggestions: int} status: ready | not_operational | few_competitors | no_provider | error */
    public function analyze(BrandClusterSerp $serp): array
    {
        $brand = $serp->brand;
        $cluster = $serp->cluster;
        if ($brand === null || $cluster === null || ! $brand->isOperational()) {
            return ['status' => 'not_operational', 'suggestions' => 0];
        }
        $competitors = $this->competitorPages($serp);
        if (count($competitors) < self::MIN_CITATIONS) {
            return ['status' => 'few_competitors', 'suggestions' => 0];
        }
        $mapping = BrandClusterPage::query()->with('page')->where('brand_id', $brand->id)->where('cluster_id', $cluster->id)
            ->where('website_asset_id', $serp->website_asset_id)->whereNotNull('page_id')->first();
        $page = $mapping?->page;

        try {
            $route = $this->routes->resolve(CompetitorAnalyzeAgent::OPERATION);
            if ($route->isEmpty()) {
                return ['status' => 'no_provider', 'suggestions' => 0];
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $agent = new CompetitorAnalyzeAgent;
            $structured = $agent->prompt(
                "DATA_JSON\n".json_encode($this->payload($brand, $cluster, $serp, $page, $competitors), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 300,
            )->toArray();
        } catch (Throwable $error) {
            Log::warning('site.competitors.analyze_failed', ['serp' => $serp->id, 'error' => $error->getMessage()]);

            return ['status' => 'error', 'suggestions' => 0];
        }

        $urls = array_column($competitors, 'url');
        $suggestions = $this->compliant($brand, self::validSuggestions((array) ($structured['suggestions'] ?? []), $urls, $page === null));
        $serp->forceFill([
            'analysis' => [
                'need' => mb_substr(trim((string) ($structured['need'] ?? '')), 0, 300),
                'page_type' => in_array($structured['dominant_page_type'] ?? null, Cluster::PAGE_TYPES, true) ? $structured['dominant_page_type'] : null,
                'missing_info' => self::lines($structured['missing_info'] ?? []),
                'local_trust' => self::lines($structured['local_trust'] ?? []),
                'decision' => in_array($structured['decision'] ?? null, CompetitorAnalyzeAgent::DECISIONS, true) ? $structured['decision'] : ($page === null ? 'new_page' : 'improve'),
                'our_page' => $page?->url,
                'competitor_urls' => $urls,
            ],
            'analyzed_at' => now(),
        ])->save();

        $count = DB::transaction(fn (): int => $this->store($brand, $cluster, $serp, $page, $suggestions, $agent->promptVersionId()));

        return ['status' => 'ready', 'suggestions' => $count];
    }

    /**
     * Keeps a suggestion only when it cites ≥ 2 distinct competitor URLs of the input, or is a gap while we have no page
     * (then ≥ 1 citation). Unknown URLs are dropped from the citations first.
     *
     * @param  array<mixed>  $rows
     * @param  list<string>  $competitorUrls
     * @return list<array{title: string, reason: string, urls: list<string>, gap: bool}>
     */
    public static function validSuggestions(array $rows, array $competitorUrls, bool $noPage): array
    {
        $known = array_flip($competitorUrls);
        $out = [];
        $titles = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            $urls = array_values(array_unique(array_filter(array_map(fn ($u): string => trim((string) $u), (array) ($row['competitor_urls'] ?? [])), fn (string $u): bool => isset($known[$u]))));
            $gap = (bool) ($row['gap'] ?? false) && $noPage;
            $key = mb_strtolower($title);
            if (mb_strlen($title) < 3 || isset($titles[$key])) {
                continue;
            }
            if (count($urls) < self::MIN_CITATIONS && ! ($gap && $urls !== [])) {
                continue;
            }
            $titles[$key] = true;
            $out[] = ['title' => mb_substr($title, 0, 160), 'reason' => mb_substr($reason, 0, 240), 'urls' => $urls, 'gap' => $gap];
            if (count($out) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{title: string, reason: string, urls: list<string>, gap: bool}>  $suggestions
     * @return list<array{title: string, reason: string, urls: list<string>, gap: bool}>
     */
    private function compliant(Brand $brand, array $suggestions): array
    {
        try {
            $rules = $this->packs->rulesForBrand($brand);
        } catch (Throwable) {
            return $suggestions;
        }
        if ($rules->isEmpty()) {
            return $suggestions;
        }

        return array_values(array_filter($suggestions, function (array $s) use ($rules): bool {
            foreach ($this->checker->checkText($s['title'].' '.$s['reason'], $rules, 'ai_draft') as $hit) {
                if (in_array($hit['rule']->severity, ['high', 'medium'], true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<array{rank: int, url: string, class: string, title: ?string, headings: list<string>, text: string}> */
    private function competitorPages(BrandClusterSerp $serp): array
    {
        $results = array_values(array_filter((array) $serp->results, fn (array $r): bool => in_array($r['class'] ?? null, CompetitorRefresher::PAGE_CLASSES, true)));
        if ($results === []) {
            return [];
        }
        $pages = CompetitorPage::query()->whereIn('url_hash', array_map(fn (array $r): string => CompetitorPageStore::hash((string) $r['url']), $results))
            ->where('status', CompetitorPage::OK)->get()->keyBy('url_hash');
        $chars = (int) config('moxdop-site.competitors.analysis_chars', 2500);
        $out = [];
        foreach ($results as $result) {
            $page = $pages->get(CompetitorPageStore::hash((string) $result['url']));
            if ($page === null) {
                continue; // eksik: not sent
            }
            $out[] = [
                'rank' => (int) $result['rank'], 'url' => (string) $result['url'], 'class' => (string) $result['class'],
                'title' => $page->title, 'headings' => array_map(fn (array $h): string => 'H'.$h['level'].' '.$h['text'], (array) $page->headings),
                'text' => mb_substr((string) $page->content_text, 0, $chars),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $competitors
     * @return array<string, mixed>
     */
    private function payload(Brand $brand, Cluster $cluster, BrandClusterSerp $serp, ?Page $page, array $competitors): array
    {
        return [
            'cluster' => [
                'name' => $cluster->name, 'need' => $cluster->reasoning, 'main_query' => $serp->query,
                'page_type' => $cluster->page_type, 'subtopics' => (array) $cluster->subtopics,
            ],
            'areas' => BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->get()
                ->map(fn (BrandServiceArea $a): string => $a->displayName().($a->physical_branch ? ' (şube)' : ''))->values()->all(),
            'our_page' => $page === null ? null : [
                'url' => $page->url, 'title' => $page->title,
                'headings' => array_map(fn (array $h): string => 'H'.$h['level'].' '.$h['text'], array_slice((array) $page->headings, 0, 40)),
                'summary' => $page->content_summary ?: mb_substr((string) $page->content_text, 0, (int) config('moxdop-site.competitors.analysis_chars', 2500)),
            ],
            'competitors' => $competitors,
        ];
    }

    /**
     * @param  list<array{title: string, reason: string, urls: list<string>, gap: bool}>  $suggestions
     */
    private function store(Brand $brand, Cluster $cluster, BrandClusterSerp $serp, ?Page $page, array $suggestions, ?int $promptVersionId): int
    {
        $keep = [];
        foreach ($suggestions as $s) {
            $fingerprint = hash('sha256', implode('|', [self::TYPE, $serp->website_asset_id, $cluster->id, SerpResults::normalize($s['title'])]));
            $keep[] = $fingerprint;
            $suggestion = Suggestion::query()->firstOrNew(['brand_id' => $brand->id, 'fingerprint' => $fingerprint]);
            $suggestion->fill([
                'channel' => 'search', 'target_type' => 'cluster', 'target_id' => $cluster->id, 'page_id' => $page?->id,
                'cluster_id' => $cluster->id, 'prompt_version_id' => $promptVersionId, 'decision_key' => self::TYPE,
                'material_hash' => hash('sha256', $s['title'].'|'.$s['reason'].'|'.implode(',', $s['urls'])),
                'title' => $s['title'], 'reason' => $s['reason'] !== '' ? $s['reason'] : ($s['gap'] ? 'Bu ihtiyaç için sayfa yok.' : count($s['urls']).' rakipte var.'),
                'priority' => $s['gap'] ? 2 : 3, 'action_type' => self::TYPE,
                'evidence' => ['competitor_urls' => $s['urls'], 'query' => $serp->query, 'gap' => $s['gap'], 'website_asset_id' => $serp->website_asset_id],
                'evidence_refs' => $s['urls'],
                'action' => ['decision' => $serp->analysis['decision'] ?? null],
                'last_seen_at' => now(),
            ]);
            if (! $suggestion->exists) {
                $suggestion->status = Suggestion::OPEN;
                $suggestion->first_seen_at = now();
            }
            $suggestion->save();
        }
        $stale = Suggestion::query()->where('brand_id', $brand->id)->where('cluster_id', $cluster->id)->where('action_type', self::TYPE)
            ->where('status', Suggestion::OPEN)->whereNotIn('fingerprint', $keep ?: [''])->get(['id', 'evidence'])
            ->filter(fn (Suggestion $s): bool => (int) ($s->evidence['website_asset_id'] ?? 0) === (int) $serp->website_asset_id)->pluck('id');
        if ($stale->isNotEmpty()) {
            Suggestion::query()->whereIn('id', $stale)->delete();
        }

        return count($suggestions);
    }

    /** @return list<string> */
    private static function lines(mixed $items): array
    {
        return array_values(array_slice(array_filter(array_map(fn ($t): string => mb_substr(trim((string) $t), 0, 200), (array) $items), fn (string $t): bool => $t !== ''), 0, 8));
    }
}
