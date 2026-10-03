<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ContentRecipeAgent;
use App\Models\Page;
use App\Services\Compliance\BriefCompliance;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * "SEO analizi" (docs/product/CONTENT_IDEAS_BLUEPRINT.md §8): the data pack of one idea row (brand, idea, matched
 * page with its technical state, coverage and gaps, Search Console score and the page's queries, site pages, sector
 * rules) → `site.content_recipe` → a checked recipe stored on the row: steps with a known area and no invented
 * number or URL, the technical step first when the page has a technical problem, SEO title ≤ 60 and meta
 * description ≤ 155 characters. "AI ile geliştir" and "AI ile üret" apply the stored recipe.
 */
final class ContentRecipe
{
    private const int PAGE_CONTENT = 9000;

    private const int MAX_STEPS = 12;

    public function __construct(
        private readonly SiteAi $ai,
        private readonly BrandMemoryService $memory,
        private readonly SiteMetrics $metrics,
        private readonly ClusterBenchmarks $benchmarks,
    ) {}

    /** @return array{status: string} */
    public function build(ContentIdeaSubject $subject): array
    {
        $brand = $subject->brand();
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational'];
        }
        $pack = $this->pack($subject);
        $result = $this->ai->run(new ContentRecipeAgent, $pack, 300);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status']];
        }
        $recipe = $this->validated($result['data'], $pack, $subject->page(), ForbiddenTerms::forBrand($brand));
        if ($recipe === null) {
            return ['status' => 'invalid'];
        }
        $subject->row->forceFill(['recipe' => $recipe + ['prompt_version_id' => $result['prompt_version_id']], 'recipe_at' => now()])->save();

        return ['status' => 'ready'];
    }

    /** @return array<string, mixed> */
    public function pack(ContentIdeaSubject $subject): array
    {
        $brand = $subject->brand();
        $cluster = $subject->cluster;
        $page = $subject->page();
        $score = $subject->score();
        $context = $this->memory->contextFor($brand, $page !== null ? [(int) $page->id] : [], [(int) $cluster->id]);
        $queries = DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->where('cq.cluster_id', $cluster->id)
            ->where('q.hidden', false)->orderByDesc('q.impressions')->orderBy('q.id')->limit(25)->get(['q.id', 'q.text', 'q.impressions', 'q.is_suggested']);
        $idea = array_filter([
            'kind' => $subject->kind, 'title' => $subject->title(), 'type' => $subject->type(), 'user_need' => (string) $cluster->user_need,
            'subtopics' => array_values((array) $cluster->subtopics),
            'top_queries' => $queries->map(fn (object $q): array => ['text' => (string) $q->text, 'impressions' => (int) $q->impressions])->values()->all(),
            'ai_questions' => ClusterAudit::aiQuestions($cluster, $brand),
            'angle' => $subject->idea?->angle, 'outline' => $subject->idea !== null ? array_values((array) $subject->idea->outline) : null,
            'target_queries' => $subject->idea !== null ? array_column((array) $subject->idea->target_queries, 'text') : null,
            'main_page_url' => $subject->mainPage()?->url,
            'service_areas' => ClusterAudit::serviceAreas($cluster, $brand) ?: null,
        ], fn (mixed $v): bool => $v !== null);

        return [
            'brand' => $context['profile'] + ['notes' => $context['notes']],
            'idea' => $idea,
            'page' => $page !== null ? [
                'url' => (string) $page->url, 'title' => $page->title, 'h1' => $page->h1, 'headings' => array_slice($page->headingTexts(), 0, 40),
                'word_count' => (int) $page->word_count, 'content' => $page->aiText(self::PAGE_CONTENT),
                'technical' => PageTechnical::of($page),
            ] : null,
            'coverage' => ['state' => (string) ($subject->row->coverage ?? 'unknown'), 'gaps' => $subject->gaps()],
            'score' => $score !== null && $score->state === 'scored' ? ['value' => (int) $score->score, 'position' => $score->position !== null ? (float) $score->position : null,
                'coverage' => (float) $score->coverage, 'ctr' => (float) $score->ctr, 'impressions' => (int) $score->impressions, 'clicks' => (int) $score->clicks] : null,
            'search_console_top_queries' => $page !== null ? $this->pageQueries($subject, $page, $queries->where('is_suggested', false)->pluck('id')->map(fn ($id): int => (int) $id)->all()) : [],
            'benchmarks' => $this->benchmarks->for($cluster, (int) $brand->id),
            'site_pages' => Page::query()->where('website_asset_id', $subject->site->id)->where('is_indexable', true)->whereIn('category', ['hizmet', 'blog', 'sss', 'lokasyon'])
                ->when($page !== null, fn ($q) => $q->whereKeyNot($page->id))->orderBy('path')->limit(80)->get(['url', 'title', 'category'])
                ->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => (string) $p->title, 'category' => (string) $p->category])->all(),
            'sector_rules' => array_column(BriefCompliance::forBrand($brand)->forPrompt(), 'instruction'),
            'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
        ];
    }

    /**
     * The page's Search Console facts on the cluster's queries (90 days), most impressions first.
     *
     * @param  list<int>  $queryIds
     * @return list<array{query: string, position: ?float, impressions: int, clicks: int}>
     */
    private function pageQueries(ContentIdeaSubject $subject, Page $page, array $queryIds): array
    {
        $brand = $subject->brand();
        $last = SiteScope::resourceIds($brand, 'search_console') !== [] ? $this->metrics->lastGscDay($brand) : null;
        if ($last === null || $queryIds === []) {
            return [];
        }
        $texts = DB::table('queries')->whereIn('id', $queryIds)->pluck('text', 'id');
        $key = SeoText::urlKey((string) $page->url);

        return collect($this->metrics->queryPageFacts($brand, $subject->site, $queryIds, [$last->subDays(ClusterPageScorer::WINDOW_DAYS - 1)->toDateString(), $last->toDateString()]))
            ->filter(fn (array $f): bool => $f['url_key'] === $key)->sortByDesc('impressions')->take(10)
            ->map(fn (array $f): array => ['query' => (string) ($texts[$f['query_id']] ?? ''), 'position' => $f['position'], 'impressions' => $f['impressions'], 'clicks' => $f['clicks']])
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $pack
     * @return array<string, mixed>|null
     */
    public function validated(array $data, array $pack, ?Page $page, ?ForbiddenTerms $forbidden = null): ?array
    {
        $evidence = new SiteEvidence([...array_column($pack['site_pages'], 'url'), (string) ($pack['page']['url'] ?? ''), (string) ($pack['idea']['main_page_url'] ?? '')]);
        $evidence->addNumbersFrom($pack);
        preg_match_all('/\d+(?:[.,]\d+)?/', (string) ($pack['page']['content'] ?? '').' '.(string) ($pack['page']['title'] ?? ''), $numbers);
        foreach (array_slice($numbers[0], 0, 500) as $number) {
            $evidence->addNumber($number);
        }
        $clean = fn (mixed $text, int $max): string => mb_substr(trim((string) $text), 0, $max);
        $steps = collect((array) ($data['steps'] ?? []))->filter(fn ($s): bool => is_array($s) && in_array($s['area'] ?? null, ContentRecipeAgent::AREAS, true))
            ->map(fn (array $s): array => ['order' => (int) ($s['order'] ?? 0), 'area' => (string) $s['area'], 'action' => $clean($s['action'] ?? '', 500),
                'where' => $clean($s['where'] ?? '', 200), 'why' => $clean($s['why'] ?? '', 400),
                'evidence' => array_values(array_slice(array_filter(array_map(fn ($e): string => $clean($e, 200), (array) ($s['evidence'] ?? []))), 0, 5))])
            ->filter(fn (array $s): bool => mb_strlen($s['action']) >= 5 && $evidence->grounded($s['action'].' '.$s['where'].' '.$s['why'].' '.implode(' ', $s['evidence']))
                && ($forbidden?->blocking($s['action'].' . '.$s['where']) ?? []) === [])
            ->sortBy('order')->values();
        $technical = $page !== null ? PageTechnical::of($page)['issues'] : [];
        if ($technical !== [] && ($steps->first()['area'] ?? null) !== 'teknik') {
            $steps->prepend(['order' => 0, 'area' => 'teknik', 'action' => $technical[0], 'where' => 'Sayfa ayarları', 'why' => 'Teknik sorun çözülmeden içerik iyileştirmesi etkisiz kalır.', 'evidence' => $technical]);
        }
        if ($steps->isEmpty()) {
            return null;
        }
        $title = $clean($data['seo_title'] ?? '', 200);
        $description = $clean($data['meta_description'] ?? '', 400);
        $summary = $clean($data['summary'] ?? '', 400);
        $effect = $clean($data['expected_effect'] ?? '', 300);

        return [
            'summary' => $evidence->grounded($summary) ? $summary : '',
            'steps' => $steps->take(self::MAX_STEPS)->values()->map(fn (array $s, int $i): array => ['order' => $i + 1] + $s)->all(),
            'seo_title' => $title !== '' && mb_strlen($title) <= 60 && $evidence->grounded($title) && ($forbidden?->blocking($title) ?? []) === [] ? $title : null,
            'meta_description' => $description !== '' && mb_strlen($description) <= 155 && $evidence->grounded($description) && ($forbidden?->blocking($description) ?? []) === [] ? $description : null,
            'expected_effect' => preg_match('/\d/', $effect) === 1 ? '' : $effect,
            'measure_after_days' => 56,
        ];
    }
}
