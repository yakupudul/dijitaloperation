<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ClusterAiQueriesAgent;
use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandContentIdea;
use App\Models\Cluster;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Brand\BrandAudit;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Kümeleri içerikle karşılaştır" — the brand's demand (clusters of its services) against the site's content:
 *
 * 1. Rows: the rule mapper places every approved cluster of the brand's services (`ClusterPageMapper`, no AI judge).
 * 2. AI questions: clusters without `ai_queries` get 4–8 questions people ask AI assistants (`queries.ai_queries`,
 *    one call per service; "{bölge}" where a place fits).
 * 3. Match (`site.cluster_match`, one call per service and language): candidate pages are chosen by word overlap of the
 *    cluster (name, main query, top queries) with the page (path, title, H1, headings); the AI reads their title,
 *    headings and opening text and names the page that answers each cluster, with coverage full / partial / none.
 *    An operator-locked page stays; only its coverage is read.
 * 4. Gaps (`site.cluster_gaps`, one call per matched page): the page text against its clusters' queries, facets, AI
 *    questions and — when the cluster needs a place (commercial / local intent, location page) — the brand's
 *    service areas → the missing items per cluster ("Eksikleri gör").
 * Rows get page, state (full → yeterli, partial → kapsam yetersiz, none → uygun sayfa yok), coverage, gaps, reason.
 * Candidates (CONTENT_IDEAS_BLUEPRINT §5.2): the page with ≥ 50 % of the cluster's Search Console impressions first,
 * then word overlap with pages of the matching category first; home / contact / about / legal pages never.
 * 5. Extra ideas of the content pool (§5.3): the same steps per idea, the main idea's page never a candidate.
 *
 * Parçalı çalışma: a run stops starting new AI calls after RUN_SECONDS (a site with many clusters needs more calls than
 * one queue job may last) and returns "partial"; the pass remembers what is done (service groups matched, pages read,
 * idea groups) so the next part continues where it stopped — no AI call is paid twice for the same pass.
 *
 * Claude (MCP): when match / gaps are delegated, a run queues every call of the current step at once and returns
 * "queued" (the pass is kept); Claude's answers dispatch the job again, which stores them and queues the next step
 * (match → gaps → extra ideas), so one Claude session drains a whole site in a few rounds.
 */
final class ClusterAudit
{
    private const int CANDIDATES_PER_CLUSTER = 6;

    private const int MAX_PAGES_PER_CALL = 60;

    private const int EXCERPT = 500;

    private const int PAGE_CONTENT = 9000;

    private const int QUERIES = 20;

    private const int MAX_GAPS = 10;

    private const array PAGE_CATEGORIES = ['hizmet', 'blog', 'sss', 'lokasyon', 'diger'];

    private const array STATES = ['full' => 'sufficient', 'partial' => 'thin_coverage', 'none' => 'no_page'];

    /** No new AI call starts after this many seconds of a run (the job timeout is 840 s, one call at most 300 s). */
    public const int RUN_SECONDS = 420;

    /** An unfinished pass older than this is started again from the beginning. */
    private const int PASS_HOURS = 24;

    private ?int $startedAt = null;

    private int $callsDone = 0;

    /** A delegated call of this run waits for Claude (MCP): the step is not finished. */
    private bool $waiting = false;

    /** @var array{since: string, matched: list<string>, gapped: list<int>, idea_groups: list<int>, idea_gapped: list<int>}|null */
    private ?array $pass = null;

    /** Tür uyumu (blueprint §5.2 c): page categories put first for a page type. */
    private const array TYPE_CATEGORIES = ['service' => ['hizmet'], 'guide' => ['blog'], 'faq' => ['sss', 'blog'], 'location' => ['lokasyon'], 'comparison' => ['blog']];

    /** Home, contact, about and legal pages are never candidates (nor cluster overlaps). */
    public const string NEVER_CANDIDATE = '#^/?$|(^|/)(iletisim|contact|hakkimizda|hakkinda|about|kvkk|gizlilik|privacy|cerez|cookie)(/|$|-)#i';

    public function __construct(
        private readonly SiteAi $ai,
        private readonly ClusterPageMapper $mapper,
        private readonly ClusterPageShares $shares,
    ) {}

    /**
     * @param  bool  $continueOnly  a follow-up part: only an open pass is continued (nothing to do when it already ended)
     * @return array{status: string, clusters: int, matched: int, gaps: int}
     */
    public function run(DigitalAsset $site, bool $continueOnly = false): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'clusters' => 0, 'matched' => 0, 'gaps' => 0];
        }
        $this->pass = $this->openPass($site);
        if ($this->pass === null) {
            if ($continueOnly) {
                return ['status' => 'ready', 'clusters' => 0, 'matched' => 0, 'gaps' => 0];
            }
            $this->pass = ['since' => now()->toIso8601String(), 'matched' => [], 'gapped' => [], 'idea_groups' => [], 'idea_gapped' => []];
        }
        $this->startedAt = now()->getTimestamp();
        $this->callsDone = 0;
        $this->waiting = false;
        try {
            return $this->runPass($site, $brand);
        } finally {
            $this->startedAt = null;
            $this->pass = null;
            $this->waiting = false;
        }
    }

    /** @return array<string, mixed> */
    private function runPass(DigitalAsset $site, Brand $brand): array
    {
        $mapped = $this->mapper->refresh($site, judge: false);
        if ($mapped['status'] !== 'ready') {
            return ['status' => $mapped['status'], 'clusters' => 0, 'matched' => 0, 'gaps' => 0];
        }
        $rows = BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName'])
            ->where('brand_id', $brand->id)->where('website_asset_id', $site->id)->where('excluded', false)->orderBy('id')->get()
            ->filter(fn (BrandClusterPage $row): bool => $row->cluster !== null);
        $clusters = $rows->pluck('cluster')->unique('id')->values();
        $this->fillAiQueries($clusters, $brand, SiteScope::primaryLanguage($site) ?? 'tr');
        if ($this->waiting) {
            // The match reads the AI questions: it waits for them, so Claude is not asked twice.
            return $this->queued($site, $rows->count());
        }
        $members = $this->members($clusters->pluck('id')->all());
        $shares = $this->shares->forClusters($brand, $site, $clusters->pluck('id')->map(fn ($id): int => (int) $id)->all());

        if ($this->outOfTime()) {
            return $this->partial($site, $rows->count());
        }

        $matched = 0;
        foreach ($rows->groupBy(fn (BrandClusterPage $row): string => ($row->language ?? '').'|'.$row->cluster->service_id) as $key => $group) {
            if (in_array((string) $key, $this->pass['matched'], true)) {
                continue;
            }
            if ($this->outOfTime()) {
                return $this->partial($site, $rows->count());
            }
            $result = $this->match($site, $group, $members, $shares);
            if ($result === 'queued') {
                $this->waiting = true;
                $this->callsDone++;

                continue;
            }
            if ($result === 'no_provider' || $result === 'error') {
                $this->savePass($site);

                return ['status' => 'ai_'.$result, 'clusters' => $rows->count(), 'matched' => $matched, 'gaps' => 0];
            }
            $matched += $result;
            $this->pass['matched'][] = (string) $key;
            $this->callsDone++;
        }

        if ($this->waiting) {
            return $this->queued($site, $rows->count());
        }

        $gaps = 0;
        $fresh = BrandClusterPage::query()->with('cluster')->whereIn('id', $rows->pluck('id'))->get();
        foreach ($fresh->whereNotNull('page_id')->groupBy('page_id') as $pageId => $pageRows) {
            if (in_array((int) $pageId, $this->pass['gapped'], true)) {
                continue;
            }
            if ($this->outOfTime()) {
                return $this->partial($site, $rows->count());
            }
            $page = Page::query()->where('website_asset_id', $site->id)->find((int) $pageId);
            if ($page !== null) {
                $found = $this->gaps($brand, $page, $pageRows, $members);
                $this->callsDone++;
                if ($found === null) {
                    $this->waiting = true;

                    continue;
                }
                $gaps += $found;
            }
            $this->pass['gapped'][] = (int) $pageId;
        }
        if ($this->waiting) {
            return $this->queued($site, $rows->count());
        }
        BrandClusterPage::query()->whereIn('id', $fresh->whereNull('page_id')->pluck('id'))
            ->update(['coverage' => 'none', 'gaps' => null, 'audited_at' => now()]);
        $ideas = $this->ideas($site, $brand);
        if ($ideas === null) {
            return $this->partial($site, $rows->count());
        }
        if ($this->waiting) {
            return $this->queued($site, $rows->count());
        }
        $overlaps = app(ClusterOverlaps::class)->sync($site, $brand, $shares);
        Cache::forget(self::passKey($site));
        SiteFlow::audited($site);

        return ['status' => 'ready', 'clusters' => $rows->count(), 'matched' => $matched, 'gaps' => $gaps, 'ideas' => $ideas, 'overlaps' => $overlaps];
    }

    /**
     * "Yeniden keşfet" (blueprint §5.5) for one main-idea row: the same match and gap steps for this row only,
     * on the stored pages.
     *
     * @return array{status: string, page_id: ?int}
     */
    public function rediscover(BrandClusterPage $row): array
    {
        $site = $row->website;
        $brand = $site !== null ? SiteScope::brandOf($site) : null;
        $row->loadMissing(['cluster.mainQuery', 'cluster.service.primaryName']);
        if ($site === null || $row->cluster === null || ! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'page_id' => null];
        }
        $this->waiting = false;
        $this->fillAiQueries(collect([$row->cluster]), $brand, SiteScope::primaryLanguage($site) ?? 'tr');
        if ($this->waiting) {
            $this->waiting = false;

            return ['status' => 'queued', 'page_id' => null];
        }
        $members = $this->members([(int) $row->cluster_id]);
        $result = $this->match($site, collect([$row]), $members, $this->shares->forClusters($brand, $site, [(int) $row->cluster_id]));
        if ($result === 'queued') {
            return ['status' => 'queued', 'page_id' => null];
        }
        if ($result === 'no_provider' || $result === 'error') {
            return ['status' => 'ai_'.$result, 'page_id' => null];
        }
        $row->refresh();
        if ($row->page_id !== null && ($page = Page::query()->where('website_asset_id', $site->id)->find($row->page_id)) !== null) {
            if ($this->gaps($brand, $page, collect([$row->load('cluster')]), $members) === null) {
                return ['status' => 'queued', 'page_id' => (int) $row->page_id];
            }
        } else {
            $row->forceFill(['coverage' => 'none', 'gaps' => null, 'audited_at' => now()])->save();
        }
        $row->forceFill(['rediscovered_at' => now()])->save();

        return ['status' => 'ready', 'page_id' => $row->page_id !== null ? (int) $row->page_id : null];
    }

    /**
     * Ek fikir ↔ URL (blueprint §5.3) for every active pool idea of the brand's clusters on this site (or one usage
     * row): usage rows are created, candidates come from the idea's title, angle and target queries — never the main
     * idea's page — the AI reads them (`site.cluster_match`, kind extra) and lists the gaps of a matched page.
     *
     * @return int|null ideas with a page; null when the run's time ran out (the pass continues in the next part)
     */
    public function ideas(DigitalAsset $site, Brand $brand, ?BrandContentIdea $only = null): ?int
    {
        $mainRows = BrandClusterPage::query()->with('cluster.service.primaryName')->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->where('excluded', false)->orderByRaw('CASE WHEN language IS NULL THEN 1 ELSE 0 END')->orderBy('id')->get()
            ->filter(fn (BrandClusterPage $row): bool => $row->cluster !== null)->unique('cluster_id')->keyBy('cluster_id');
        $ideas = ContentIdea::query()->where('status', 'active')->whereIn('cluster_id', $mainRows->keys()->all() ?: [0])
            ->when($only !== null, fn ($q) => $q->whereKey($only->content_idea_id))->orderBy('id')->get();
        if ($ideas->isEmpty()) {
            return 0;
        }
        $usages = $ideas->map(fn (ContentIdea $idea): BrandContentIdea => BrandContentIdea::query()->firstOrCreate(
            ['brand_id' => $brand->id, 'content_idea_id' => $idea->id, 'website_asset_id' => $site->id])->setRelation('idea', $idea));
        $pages = $this->candidatePages($site, SiteScope::primaryLanguage($site));
        $pageWords = $this->pageWords($pages);
        $matched = 0;
        foreach ($usages->groupBy(fn (BrandContentIdea $u): int => (int) $mainRows[$u->idea->cluster_id]->cluster->service_id) as $serviceId => $group) {
            if ($only === null && $this->pass !== null && in_array((int) $serviceId, $this->pass['idea_groups'], true)) {
                continue;
            }
            if ($this->outOfTime()) {
                return null;
            }
            $candidates = [];
            foreach ($group as $usage) {
                $idea = $usage->idea;
                $main = $mainRows[$idea->cluster_id];
                $words = SeoText::tokens(implode(' ', [$idea->title, $idea->angle, ...array_column((array) $idea->target_queries, 'text')]));
                $candidates[(int) $usage->id] = $this->candidates($words, (string) $idea->type, $pages, $pageWords, null,
                    $main->pageIds(), $usage->locked ? $usage->page_id : null);
            }
            $pageIds = array_slice(array_values(array_unique(array_merge(...array_values($candidates ?: [[]])))), 0, self::MAX_PAGES_PER_CALL);
            $answers = collect();
            if ($pageIds !== []) {
                $byId = $pages->keyBy('id');
                $result = $this->ai->run(new ClusterMatchAgent, [
                    'service' => (string) ($mainRows[$group->first()->idea->cluster_id]->cluster->service?->primaryName?->raw_label ?? ''),
                    'clusters' => $group->map(fn (BrandContentIdea $u): array => [
                        'cluster_id' => (int) $u->id, 'kind' => 'extra', 'name' => (string) $u->idea->title, 'angle' => (string) $u->idea->angle,
                        'main_query' => (string) (((array) $u->idea->target_queries)[0]['text'] ?? ''), 'facets' => [],
                        'queries' => array_column((array) $u->idea->target_queries, 'text'), 'ai_queries' => [],
                        'main_page_url' => $mainRows[$u->idea->cluster_id]->page?->url, 'fixed_page_id' => $u->locked ? $u->page_id : null,
                    ])->values()->all(),
                    'pages' => array_map(fn (int $id): array => $this->pagePack($byId[$id]), $pageIds),
                ], 300);
                $this->callsDone++;
                if ($result['status'] === 'queued') {
                    $this->waiting = true;

                    continue;
                }
                if ($result['status'] !== 'ready') {
                    return $matched;
                }
                $answers = collect((array) ($result['data']['clusters'] ?? []))->filter(fn ($r): bool => is_array($r) && is_int($r['cluster_id'] ?? null))->keyBy('cluster_id');
            }
            foreach ($group as $usage) {
                $answer = $answers->get($usage->id);
                $pageId = is_int($answer['page_id'] ?? null) && in_array($answer['page_id'], $candidates[(int) $usage->id], true) ? $answer['page_id'] : null;
                if ($usage->locked) {
                    $pageId = $usage->page_id !== null ? (int) $usage->page_id : null;
                }
                $coverage = in_array($answer['coverage'] ?? null, ClusterMatchAgent::COVERAGE, true) ? $answer['coverage'] : 'none';
                $coverage = $pageId === null ? 'none' : ($coverage === 'none' ? 'partial' : $coverage);
                $reason = mb_substr(trim((string) ($answer['reason'] ?? '')), 0, 300);
                $reason = preg_match('/https?:|www\.|\d{2,}/u', $reason) === 1 ? '' : $reason;
                if ($pageId !== null && $this->pass !== null && in_array((int) $usage->id, $this->pass['idea_gapped'], true) && (int) $usage->page_id === $pageId) {
                    $matched++; // read in an earlier part of this pass: same page, its gaps stay

                    continue;
                }
                $usage->forceFill(['page_id' => $pageId, 'coverage' => $coverage, 'gaps' => null, 'audited_at' => now(),
                    'state' => $pageId === null ? 'no_page' : ($coverage === 'full' ? 'sufficient' : 'improve'),
                    'reason' => $reason !== '' ? $reason : ($pageId === null ? 'Sitede bu konuyu işleyen ayrı bir sayfa yok.' : null)])->save();
                if ($pageId !== null) {
                    $matched++;
                    if ($this->outOfTime()) {
                        return null;
                    }
                    $this->callsDone++;
                    if (! $this->ideaGaps($brand, $usage)) {
                        $this->waiting = true;

                        continue;
                    }
                    if ($this->pass !== null) {
                        $this->pass['idea_gapped'][] = (int) $usage->id;
                    }
                }
            }
            if ($this->pass !== null) {
                $this->pass['idea_groups'][] = (int) $serviceId;
            }
        }

        return $matched;
    }

    /** "Yeniden keşfet" for one extra idea row. @return array{status: string, page_id: ?int} */
    public function rediscoverIdea(BrandContentIdea $usage): array
    {
        $site = $usage->website;
        $brand = $site !== null ? SiteScope::brandOf($site) : null;
        if ($site === null || ! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'page_id' => null];
        }
        $this->waiting = false;
        $this->ideas($site, $brand, $usage);
        if ($this->waiting) {
            $this->waiting = false;

            return ['status' => 'queued', 'page_id' => null];
        }
        $usage->refresh()->forceFill(['rediscovered_at' => now()])->save();

        return ['status' => 'ready', 'page_id' => $usage->page_id !== null ? (int) $usage->page_id : null];
    }

    /** Gaps of the page matched to an extra idea (title, target queries, outline as subtopics); false while Claude has not answered. */
    private function ideaGaps(Brand $brand, BrandContentIdea $usage): bool
    {
        $page = Page::query()->find($usage->page_id);
        if ($page === null) {
            return true;
        }
        $result = $this->ai->run(new ClusterGapsAgent, [
            'page' => ['url' => (string) $page->url, 'title' => $page->title, 'h1' => $page->h1, 'headings' => array_slice($page->headingTexts(), 0, 40),
                'content' => $page->aiText(self::PAGE_CONTENT)],
            'clusters' => [['cluster_id' => (int) $usage->id, 'name' => (string) $usage->idea->title, 'facets' => [],
                'queries' => array_column((array) $usage->idea->target_queries, 'text'), 'ai_queries' => [], 'subtopics' => array_values((array) $usage->idea->outline)]],
        ], 300);
        if ($result['status'] === 'queued') {
            return false;
        }
        $answer = $result['status'] === 'ready' ? collect((array) ($result['data']['clusters'] ?? []))->first(fn ($r): bool => is_array($r) && ($r['cluster_id'] ?? null) === (int) $usage->id) : null;
        if ($answer === null) {
            return true;
        }
        $gaps = collect((array) ($answer['gaps'] ?? []))->filter(fn ($g): bool => is_array($g) && in_array($g['kind'] ?? null, ClusterGapsAgent::KINDS, true) && $g['kind'] !== 'lokasyon')
            ->map(fn (array $g): array => ['text' => mb_substr(trim((string) ($g['text'] ?? '')), 0, 200), 'kind' => (string) $g['kind']])
            ->filter(fn (array $g): bool => mb_strlen($g['text']) >= 5)->take(self::MAX_GAPS)->values()->all();
        $coverage = $gaps === [] ? 'full' : 'partial';
        $usage->forceFill(['coverage' => $coverage, 'gaps' => $gaps, 'state' => $coverage === 'full' ? 'sufficient' : 'improve', 'audited_at' => now()])->save();

        return true;
    }

    /** Whether this run must stop starting AI calls (at least one call was made, so every part makes progress). */
    private function outOfTime(): bool
    {
        return $this->startedAt !== null && $this->callsDone > 0 && now()->getTimestamp() - $this->startedAt >= self::RUN_SECONDS;
    }

    /** @return array<string, mixed> */
    private function partial(DigitalAsset $site, int $clusters): array
    {
        $this->savePass($site);

        return ['status' => 'partial', 'clusters' => $clusters, 'matched' => 0, 'gaps' => 0];
    }

    /** @return array<string, mixed> a step waits for Claude (MCP): the pass is kept; Claude's answers run the job again */
    private function queued(DigitalAsset $site, int $clusters): array
    {
        $this->savePass($site);

        return ['status' => 'queued', 'clusters' => $clusters, 'matched' => 0, 'gaps' => 0];
    }

    private function savePass(DigitalAsset $site): void
    {
        if ($this->pass !== null) {
            Cache::put(self::passKey($site), $this->pass, now()->addHours(self::PASS_HOURS));
        }
    }

    /** @return array{since: string, matched: list<string>, gapped: list<int>, idea_groups: list<int>, idea_gapped: list<int>}|null */
    private function openPass(DigitalAsset $site): ?array
    {
        $pass = Cache::get(self::passKey($site));

        return is_array($pass) && isset($pass['since']) ? $pass + ['matched' => [], 'gapped' => [], 'idea_groups' => [], 'idea_gapped' => []] : null;
    }

    /** Whether a pass of this site stopped half way and waits for its next part or Claude's answers. */
    public static function passOpen(DigitalAsset|int $site): bool
    {
        return Cache::has(self::passKey($site));
    }

    private static function passKey(DigitalAsset|int $site): string
    {
        return 'cluster-audit:pass:'.($site instanceof DigitalAsset ? $site->id : $site);
    }

    /**
     * Lokasyon kuralı: a cluster whose content should name the places the business serves — a commercial or local
     * need (people choose a provider near them: "implant kliniği") or a location page. Informational / comparison
     * needs ("implant sonrası ne yenir") stay place-free.
     */
    public static function needsLocation(Cluster $cluster): bool
    {
        return in_array($cluster->intent, ['commercial', 'local'], true) || $cluster->page_type === 'location';
    }

    /**
     * The brand's service areas for a cluster that needs a place (empty otherwise): "Karşıyaka", "Bornova"…
     *
     * @return list<string>
     */
    public static function serviceAreas(Cluster $cluster, Brand $brand): array
    {
        if (! self::needsLocation($cluster)) {
            return [];
        }

        return SiteScope::areas($brand)->map(fn ($area): string => trim((string) ($area->district_name ?: $area->city_name ?: $area->name)))
            ->filter()->unique()->take(12)->values()->all();
    }

    /**
     * The cluster's AI-assistant questions for this brand: "{bölge}" becomes the brand's main area; questions holding
     * the placeholder are left out when the brand has no area.
     *
     * @return list<string>
     */
    public static function aiQuestions(Cluster $cluster, Brand $brand): array
    {
        $area = SiteScope::targetArea($brand);
        $place = $area !== null ? trim((string) ($area->district_name ?: $area->city_name ?: $area->name)) : '';

        return collect((array) $cluster->ai_queries)->map(fn ($q): string => (string) $q)
            ->filter(fn (string $q): bool => $place !== '' || ! str_contains($q, '{bölge}'))
            ->map(fn (string $q): string => str_replace('{bölge}', $place, $q))->values()->all();
    }

    /**
     * Facets and top queries of the clusters (real queries, most impressions first).
     *
     * @param  list<int>  $clusterIds
     * @return array<int, array{queries: list<string>, facets: list<string>}>
     */
    private function members(array $clusterIds): array
    {
        $out = [];
        DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')
            ->whereIn('cq.cluster_id', $clusterIds ?: [0])->where('q.hidden', false)
            ->orderBy('cq.cluster_id')->orderByDesc('q.impressions')->orderBy('q.id')
            ->get(['cq.cluster_id', 'q.text', 'q.facets'])
            ->each(function (object $row) use (&$out): void {
                $entry = &$out[(int) $row->cluster_id];
                $entry ??= ['queries' => [], 'facets' => []];
                if (count($entry['queries']) < self::QUERIES) {
                    $entry['queries'][] = (string) $row->text;
                }
                foreach (array_filter(explode(',', (string) $row->facets)) as $facet) {
                    $entry['facets'][$facet] = $facet;
                }
            });

        return array_map(fn (array $entry): array => ['queries' => $entry['queries'], 'facets' => array_values($entry['facets'])], $out);
    }

    /**
     * Clusters without AI questions get them, one call per service.
     *
     * @param  Collection<int, Cluster>  $clusters
     */
    public function fillAiQueries(Collection $clusters, ?Brand $brand, string $language): void
    {
        $missing = $clusters->filter(fn (Cluster $c): bool => $c->ai_queries === null);
        if ($missing->isEmpty()) {
            return;
        }
        $members = $this->members($missing->pluck('id')->all());
        $sector = DB::table('service_categories')->where('id', $missing->first()->sector_id)->value('name');
        foreach ($missing->groupBy('service_id') as $group) {
            foreach ($group->chunk(40) as $chunk) {
                $result = $this->ai->run(new ClusterAiQueriesAgent, [
                    'sector' => (string) $sector, 'service' => (string) ($chunk->first()->service?->primaryName?->raw_label ?? ''), 'language' => $language,
                    'clusters' => $chunk->map(fn (Cluster $c): array => ['cluster_id' => (int) $c->id, 'name' => (string) $c->name, 'intent' => (string) $c->intent,
                        'main_query' => (string) ($c->mainQuery?->text ?? ''), 'facets' => $members[$c->id]['facets'] ?? [],
                        'queries' => array_slice($members[$c->id]['queries'] ?? [], 0, 10), 'local' => self::needsLocation($c)])->values()->all(),
                ], 180);
                if ($result['status'] === 'queued') {
                    $this->waiting = true;

                    continue;
                }
                if ($result['status'] !== 'ready') {
                    return;
                }
                $byId = $chunk->keyBy('id');
                // A cluster the AI gave no questions for is not asked again on every run.
                Cluster::query()->whereIn('id', $chunk->pluck('id'))->whereNull('ai_queries')->update(['ai_queries' => '[]']);
                foreach ((array) ($result['data']['clusters'] ?? []) as $row) {
                    $cluster = is_array($row) && is_int($row['cluster_id'] ?? null) ? $byId->get($row['cluster_id']) : null;
                    if ($cluster === null) {
                        continue;
                    }
                    $questions = collect((array) ($row['questions'] ?? []))->map(fn ($q): string => mb_substr(trim((string) $q), 0, 200))
                        ->filter(fn (string $q): bool => mb_strlen($q) >= 8)->unique()->take(8)->values()->all();
                    $cluster->forceFill(['ai_queries' => $questions])->save();
                }
            }
        }
    }

    /**
     * @param  Collection<int, BrandClusterPage>  $rows  one service, one language
     * @param  array<int, array{queries: list<string>, facets: list<string>}>  $members
     * @param  array<int, list<array{url: string, url_key: string, impressions: int, share: float}>>  $shares
     */
    private function match(DigitalAsset $site, Collection $rows, array $members, array $shares = []): int|string
    {
        $pages = $this->candidatePages($site, $rows->first()->language);
        $pageWords = $this->pageWords($pages);
        // A page linked to other services of the brand (and not to this cluster's service) is never a candidate.
        $pageServices = BrandAudit::pageServices($site);
        $candidates = [];
        foreach ($rows as $row) {
            $cluster = $row->cluster;
            $foreign = array_keys(array_filter($pageServices, fn (array $services): bool => ! in_array((int) $cluster->service_id, $services, true)));
            $words = SeoText::tokens(implode(' ', [$cluster->name, $cluster->mainQuery?->text, ...array_slice($members[$cluster->id]['queries'] ?? [], 0, 20)]));
            $lead = $shares[(int) $cluster->id][0] ?? null;
            $candidates[(int) $row->id] = $this->candidates($words, (string) $cluster->page_type, $pages, $pageWords,
                $lead !== null && $lead['share'] >= ClusterPageShares::LEAD ? $lead['url_key'] : null, $foreign, $row->locked ? $row->page_id : null);
        }
        $pageIds = array_slice(array_values(array_unique(array_merge(...array_values($candidates ?: [[]])))), 0, self::MAX_PAGES_PER_CALL);
        if ($pageIds === []) {
            foreach ($rows as $row) {
                $this->place($row, null, 'none', 'Sitede bu konuyu işleyen sayfa bulunamadı.', []);
            }

            return 0;
        }
        $byId = $pages->keyBy('id');
        // Claude (MCP): the call is named by its language, service and clusters, so a re-run of the pass finds the answer
        // although candidate pages, queries or page texts moved while it waited (no second task for the same question).
        $slot = 'match:'.($rows->first()->language ?? '').'|'.$rows->first()->cluster->service_id.'|'
            .$rows->map(fn (BrandClusterPage $row): int => (int) $row->cluster_id)->sort()->implode(',');
        $result = $this->ai->run(new ClusterMatchAgent, [
            'service' => (string) ($rows->first()->cluster->service?->primaryName?->raw_label ?? ''),
            'clusters' => $rows->map(fn (BrandClusterPage $row): array => [
                'cluster_id' => (int) $row->cluster_id, 'name' => (string) $row->cluster->name, 'main_query' => (string) ($row->cluster->mainQuery?->text ?? ''),
                'facets' => $members[$row->cluster_id]['facets'] ?? [], 'queries' => array_slice($members[$row->cluster_id]['queries'] ?? [], 0, 12),
                'ai_queries' => array_slice((array) $row->cluster->ai_queries, 0, 6), 'fixed_page_id' => $row->locked ? $row->page_id : null,
            ])->values()->all(),
            'pages' => array_map(fn (int $id): array => $this->pagePack($byId[$id]), $pageIds),
        ], 300, $slot);
        if ($result['status'] !== 'ready') {
            return $result['status'];
        }
        $answers = collect((array) ($result['data']['clusters'] ?? []))->filter(fn ($r): bool => is_array($r) && is_int($r['cluster_id'] ?? null))->keyBy('cluster_id');
        $matched = 0;
        foreach ($rows as $row) {
            $answer = $answers->get($row->cluster_id);
            $coverage = in_array($answer['coverage'] ?? null, ClusterMatchAgent::COVERAGE, true) ? $answer['coverage'] : 'none';
            $pageId = is_int($answer['page_id'] ?? null) && in_array($answer['page_id'], $candidates[(int) $row->id], true) ? $answer['page_id'] : null;
            if ($row->locked) {
                $pageId = $row->page_id !== null ? (int) $row->page_id : null;
            }
            $coverage = $pageId === null ? 'none' : ($coverage === 'none' ? 'partial' : $coverage);
            $reason = mb_substr(trim((string) ($answer['reason'] ?? '')), 0, 300);
            // Other candidate pages the AI says answer the same need: the cluster's overlapping pages.
            $also = array_values(array_unique(array_filter((array) ($answer['also_page_ids'] ?? []),
                fn ($id): bool => is_int($id) && $id !== $pageId && in_array($id, $candidates[(int) $row->id], true))));
            // A reason with a URL or a number of two or more digits is not from the page text: dropped.
            $this->place($row, $pageId, $coverage, preg_match('/https?:|www\.|\d{2,}/u', $reason) === 1 ? '' : $reason, $pageId !== null ? $also : []);
            $matched += $pageId !== null ? 1 : 0;
        }

        return $matched;
    }

    /**
     * Pages that may answer a need: indexable, of a content category (or none), in the language; never home,
     * contact, about or legal pages.
     *
     * @return Collection<int, Page>
     */
    private function candidatePages(DigitalAsset $site, ?string $language): Collection
    {
        return Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)
            ->where(fn ($q) => $q->whereIn('category', self::PAGE_CATEGORIES)->orWhereNull('category'))
            ->when($language !== null, fn ($q) => $q->where(fn ($l) => $l->where('language', $language)->orWhereNull('language')))
            ->orderBy('id')->get(['id', 'url', 'path', 'title', 'h1', 'headings', 'content_text', 'content_outline', 'category'])
            ->reject(fn (Page $p): bool => preg_match(self::NEVER_CANDIDATE, '/'.trim((string) $p->path, '/')) === 1 || trim((string) $p->path, '/') === '')
            ->values();
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return array<int, array<string, int>> page id → words of path, title, H1 and headings
     */
    private function pageWords(Collection $pages): array
    {
        return $pages->mapWithKeys(fn (Page $p): array => [(int) $p->id => array_flip(SeoText::tokens(implode(' ', [
            str_replace(['/', '-', '_'], ' ', (string) $p->path), $p->title, $p->h1, implode(' ', array_slice($p->headingTexts(), 0, 20)),
        ])))])->all();
    }

    /**
     * Aday sayfalar (blueprint §5.2): the Search Console lead page first, then pages by word overlap with the
     * pages of a matching category put first; at most CANDIDATES_PER_CLUSTER. Excluded pages never; a fixed
     * (operator) page always.
     *
     * @param  list<string>  $words
     * @param  Collection<int, Page>  $pages
     * @param  array<int, array<string, int>>  $pageWords
     * @param  list<int>  $exclude
     * @return list<int>
     */
    private function candidates(array $words, string $pageType, Collection $pages, array $pageWords, ?string $leadUrlKey, array $exclude, ?int $fixed): array
    {
        $preferred = self::TYPE_CATEGORIES[$pageType] ?? [];
        $byId = $pages->keyBy('id');
        $scores = [];
        foreach ($pageWords as $pageId => $set) {
            if (in_array($pageId, $exclude, true)) {
                continue;
            }
            $hits = count(array_filter($words, fn (string $w): bool => isset($set[$w])));
            if ($hits > 0) {
                $scores[$pageId] = [in_array($byId[$pageId]->category, $preferred, true) ? 1 : 0, $hits, -$pageId];
            }
        }
        uasort($scores, fn (array $a, array $b): int => $b <=> $a);
        $ids = array_keys($scores);
        if ($leadUrlKey !== null) {
            $lead = $pages->first(fn (Page $p): bool => SeoText::urlKey((string) $p->url) === $leadUrlKey && ! in_array((int) $p->id, $exclude, true));
            if ($lead !== null) {
                $ids = [(int) $lead->id, ...array_values(array_diff($ids, [(int) $lead->id]))];
            }
        }
        $ids = array_slice($ids, 0, self::CANDIDATES_PER_CLUSTER);
        if ($fixed !== null && $byId->has($fixed) && ! in_array((int) $fixed, $ids, true)) {
            $ids[] = (int) $fixed;
        }

        return $ids;
    }

    /** @return array<string, mixed> what the AI reads of a candidate page */
    private function pagePack(Page $page): array
    {
        return [
            'id' => (int) $page->id, 'url' => (string) $page->url, 'title' => $page->title, 'h1' => $page->h1,
            'headings' => array_slice($page->headingTexts(), 0, 15),
            'excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $page->content_text) ?? ''), 0, self::EXCERPT),
        ];
    }

    /** @param  list<int>  $overlaps */
    private function place(BrandClusterPage $row, ?int $pageId, string $coverage, string $reason, array $overlaps): void
    {
        $values = ['coverage' => $coverage, 'reason' => $reason !== '' ? $reason : $row->reason, 'audited_at' => now(), 'overlap_page_ids' => $overlaps ?: null];
        if (! $row->locked) {
            $values += ['page_id' => $pageId, 'state' => self::STATES[$coverage], 'decided_by' => 'ai'];
        }
        $row->forceFill($values)->save();
    }

    /**
     * @param  Collection<int, BrandClusterPage>  $rows  the rows targeting this page
     * @param  array<int, array{queries: list<string>, facets: list<string>}>  $members
     * @return int|null gaps found; null while Claude (MCP) has not answered
     */
    private function gaps(Brand $brand, Page $page, Collection $rows, array $members): ?int
    {
        $result = $this->ai->run(new ClusterGapsAgent, [
            'page' => ['url' => (string) $page->url, 'title' => $page->title, 'h1' => $page->h1, 'headings' => array_slice($page->headingTexts(), 0, 40),
                'content' => $page->aiText(self::PAGE_CONTENT)],
            'clusters' => $rows->map(fn (BrandClusterPage $row): array => array_filter([
                'cluster_id' => (int) $row->cluster_id, 'name' => (string) $row->cluster->name, 'facets' => $members[$row->cluster_id]['facets'] ?? [],
                'queries' => $members[$row->cluster_id]['queries'] ?? [], 'ai_queries' => self::aiQuestions($row->cluster, $brand),
                'service_areas' => self::serviceAreas($row->cluster, $brand) ?: null,
            ], fn (mixed $v): bool => $v !== null))->values()->all(),
        ], 300);
        if ($result['status'] === 'queued') {
            return null;
        }
        if ($result['status'] !== 'ready') {
            return 0;
        }
        $answers = collect((array) ($result['data']['clusters'] ?? []))->filter(fn ($r): bool => is_array($r) && is_int($r['cluster_id'] ?? null))->keyBy('cluster_id');
        $count = 0;
        foreach ($rows as $row) {
            $answer = $answers->get($row->cluster_id);
            if ($answer === null) {
                continue;
            }
            $gaps = collect((array) ($answer['gaps'] ?? []))->filter(fn ($g): bool => is_array($g) && in_array($g['kind'] ?? null, ClusterGapsAgent::KINDS, true))
                ->map(fn (array $g): array => ['text' => mb_substr(trim((string) ($g['text'] ?? '')), 0, 200), 'kind' => (string) $g['kind']])
                ->filter(fn (array $g): bool => mb_strlen($g['text']) >= 5)
                ->reject(fn (array $g): bool => $g['kind'] === 'lokasyon' && self::serviceAreas($row->cluster, $brand) === [])
                ->take(self::MAX_GAPS)->values()->all();
            $coverage = in_array($answer['coverage'] ?? null, ['full', 'partial'], true) ? $answer['coverage'] : 'partial';
            $coverage = $gaps === [] ? 'full' : ($coverage === 'full' ? 'partial' : $coverage);
            $values = ['coverage' => $coverage, 'gaps' => $gaps, 'audited_at' => now()];
            if (! $row->locked) {
                $values['state'] = self::STATES[$coverage];
            }
            $row->forceFill($values)->save();
            $count += count($gaps);
        }

        return $count;
    }
}
