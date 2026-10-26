<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ContentBriefAgent;
use App\Ai\Agents\Site\ContentDiscoveryAgent;
use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\ContentTopic;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Query;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandFacts;
use App\Services\Compliance\BriefCompliance;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Epicrisis\Epicrisis;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Meta\MetaDesk;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Queries\QueryNormalizer;
use App\Services\SeoTasks\SeoText;
use App\Services\SeoTasks\SiteUrlPattern;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Work\ContentCoverage;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

    /** What makes a weekly idea more than "one more article" (shown on the title card). */
    public const array ANGLES = [
        'decision' => 'Karar desteği', 'comparison' => 'Karşılaştırma', 'process' => 'Süreç', 'local' => 'Yerel',
        'expert_answer' => 'AI aramalarına net yanıt', 'objection' => 'Endişe / yanlış bilinen', 'update' => 'Mevcut sayfayı güçlendir', 'insight' => 'SEO öngörüsü',
    ];

    private const array URL_TYPES = ['hizmet' => 'service', 'blog' => 'guide', 'sss' => 'faq', 'lokasyon' => 'location'];

    private const array CLUSTER_PAGE_TYPES = ['hizmet' => 'service', 'blog' => 'guide', 'sss' => 'faq', 'lokasyon' => 'location'];

    /** Gaps read per run; the best ones (brand's own searches, demand, main services) go to the AI. */
    private const int GAP_CANDIDATES = 300;

    /** Why an update idea is not stored: its page already has one waiting. */
    public const string PAGE_TAKEN = 'bu sayfa için bekleyen güncelleme fikri var';

    /** Why a new idea is not stored: another idea of the site already has its subject (ContentMap). */
    public const string SAME_SUBJECT = 'aynı konuda başka fikir var';

    /** Search Console queries the site already shows for but not near the top: the strongest idea material. */
    private const int STRIKING_QUERIES = 40;

    /**
     * Düşen yazı (2026-10-26, "kümelerin hepsi dolunca neye göre içerik?"): a page with at least DECAY_MIN_PREVIOUS
     * Search Console clicks in the previous 90 days that lost DECAY_DROP of them in the last 90 is a refresh candidate.
     * Once a site's clusters have pages, this is where most of the work comes from.
     */
    public const int DECAY_MIN_PREVIOUS = 20;

    public const float DECAY_DROP = 0.3;

    private const int DECAY_PAGES = 20;

    private const array MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public function __construct(
        private readonly SiteAi $ai,
        private readonly BrandMemoryService $memory,
        private readonly SiteAnalysisReader $reader,
    ) {}

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
     * One waiting update idea per page: of several open update ideas for the same page (no article yet), the first
     * stays and the rest are closed so the pool tops up with other topics.
     *
     * @return int ideas closed
     */
    public static function retireDuplicateUpdates(): int
    {
        $ids = [];
        $seen = Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->whereNotNull('page_id')
            ->whereIn('status', [Suggestion::APPROVED, Suggestion::SNOOZED])->pluck('page_id')->map(fn ($id): int => (int) $id)->flip()->all();
        Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::OPEN)->whereNotNull('page_id')->orderBy('id')
            ->each(function (Suggestion $s) use (&$ids, &$seen): void {
                if (data_get($s->action, 'kind') !== 'update' || is_array(data_get($s->action, 'article'))) {
                    return;
                }
                if (isset($seen[(int) $s->page_id])) {
                    $ids[] = (int) $s->id;

                    return;
                }
                $seen[(int) $s->page_id] = true;
            });
        $closed = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $closed += Suggestion::query()->whereIn('id', $chunk)->update(['status' => Suggestion::DISMISSED, 'resolved_at' => now(),
                'operator_note' => 'Aynı sayfa için başka bir güncelleme fikri bekliyor; sayfa başına bir güncelleme.', 'updated_at' => now()]);
        }

        return $closed;
    }

    /**
     * yakup (2026-10-07): other languages get no ideas of their own. Open ideas planned in a language other than their
     * site's main one (no article yet) are closed; the main-language idea's article is translated instead.
     */
    public static function retireOtherLanguageIdeas(): int
    {
        $closed = 0;
        $mains = [];
        Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::OPEN)->orderBy('id')
            ->chunkById(500, function (Collection $ideas) use (&$closed, &$mains): void {
                $ids = $ideas->filter(function (Suggestion $s) use (&$mains): bool {
                    $action = (array) $s->action;
                    $language = strtolower((string) ($action['language'] ?? ''));
                    if ($language === '' || is_array($action['article'] ?? null)) {
                        return false;
                    }
                    $siteId = (int) ($action['site_id'] ?? 0);
                    if (! array_key_exists($siteId, $mains)) {
                        $site = DigitalAsset::query()->find($siteId);
                        $mains[$siteId] = $site !== null ? self::siteLanguages($site)[0] : null;
                    }

                    return $mains[$siteId] !== null && $language !== $mains[$siteId];
                })->pluck('id')->all();
                if ($ids !== []) {
                    $closed += Suggestion::query()->whereIn('id', $ids)->update(['status' => Suggestion::DISMISSED, 'resolved_at' => now(),
                        'operator_note' => 'Diğer diller için ayrı fikir üretilmez; ana dildeki yazı yazılınca bu dile çevrilir.', 'updated_at' => now()]);
                }
            });

        return $closed;
    }

    /**
     * Waiting ideas whose page is in another language than the idea (opcklinik.com, 2026-10-12: Turkish ideas pointed
     * at /de/ pages). An update of another-language page becomes a new post of its own language, keeping its approval
     * and article; a new idea's address under another language's prefix gets the site's own address pattern.
     *
     * @return array{updates: int, addresses: int}
     */
    public function repairLanguageTargets(): array
    {
        $counts = ['updates' => 0, 'addresses' => 0];
        $sites = [];
        Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED])
            ->whereNull('action->article_write_id')->with('page:id,url,path,language')->orderBy('id')
            ->each(function (Suggestion $idea) use (&$counts, &$sites): void {
                $action = (array) $idea->action;
                $siteId = (int) ($action['site_id'] ?? 0);
                if (! array_key_exists($siteId, $sites)) {
                    $site = DigitalAsset::query()->where('type', 'website')->find($siteId);
                    $sites[$siteId] = $site !== null ? [$site, self::urlPattern($site, $this->sitePages($site))] : null;
                }
                if ($sites[$siteId] === null) {
                    return;
                }
                [$site, $pattern] = $sites[$siteId];
                $language = self::articleLanguage($idea, $site);
                $isUpdate = ($action['kind'] ?? null) === 'update';
                if ($isUpdate && $idea->page !== null && self::inLanguage($idea->page, $language)) {
                    return;
                }
                if (! $isUpdate) {
                    $target = (string) ($action['target_url'] ?? '');
                    $probe = (new Page)->forceFill(['url' => $target, 'path' => (string) parse_url($target, PHP_URL_PATH)]);
                    if ($target === '' || self::inLanguage($probe, $language)) {
                        return;
                    }
                }
                if ($isUpdate && $idea->page === null) {
                    return; // the page left the site: the writer says so when the idea is written
                }
                $pageType = in_array($action['page_type'] ?? null, self::PAGE_TYPES, true) ? $action['page_type'] : 'blog';
                $action['target_url'] = $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify((string) $idea->title), (string) ($idea->cluster?->service?->primaryName?->raw_label ?? ''));
                $action['kind'] = 'new';
                if (($action['angle'] ?? null) === 'update') {
                    unset($action['angle']);
                }
                $idea->forceFill(['action' => $action] + ($isUpdate ? ['page_id' => null, 'target_type' => 'site', 'target_id' => $site->id] : []))->save();
                $counts[$isUpdate ? 'updates' : 'addresses']++;
            });

        return $counts;
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
     * İçerik fikir havuzu (yakup, 2026-10-09 "konuyu veri seçsin"): the topics come from rules, the AI only writes them.
     * Candidates are the site's Search Console queries at position 4–20 without a page of their own, clusters without a
     * suitable page or with thin coverage, weak pages to strengthen and the questions people ask AI assistants, each with
     * its numbers as evidence, scored (demand × service weight × what earlier articles of the angle brought) and mixed
     * (about 60% new, 25% updates, 15% AI questions). The AI writes one title, angle and outline per candidate; what a
     * rule drops is asked once more with the reason, and every run leaves its counts and reasons on the content line
     * (`lastRun`). Ideas are only planned in the site's main language (yakup, 2026-10-07).
     *
     * @param  Collection<int, BrandClusterPage>|null  $only  "Konu üret": just these rows, one item
     * @param  array<string, int>|null  $wants  ideas per language; null: the brand's weekly number in the main language
     * @param  array{new: int, update: int}|null  $cycle  haftalık döngü (Wednesday): exactly this many new articles and updates,
     *                                                    taken from the topic maps of the site's active services (ContentTopics)
     * @return array{status: string, added: int}
     */
    public function weekly(DigitalAsset $site, ?Collection $only = null, ?array $wants = null, ?array $cycle = null): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'added' => 0];
        }
        $main = self::siteLanguages($site)[0];
        $requested = collect($wants ?? [])->filter(fn ($n, $l): bool => $l === $main && (int) $n > 0)->map(fn ($n): int => min(20, (int) $n))->all();
        $explicit = $requested !== [] && $only === null;
        $capacity = $only !== null ? 1 : ($requested[$main] ?? max(1, min(20, (int) ($brand->weekly_content_capacity ?? 4))));
        $sitePages = $this->sitePages($site);
        $previous = $this->previousIdeas($brand);
        if ($cycle !== null && $only === null) {
            $candidates = $this->cycleCandidates($brand, $site, $previous, $sitePages, $main, $cycle);
            if ($candidates === null) {
                $this->logRun($site, ['status' => 'queued', 'candidates' => 0, 'asked' => 0, 'added' => 0, 'dropped' => [], 'message' => 'Konu haritası Claude kuyruğunda çiziliyor.']);

                return ['status' => 'queued', 'added' => 0];
            }
            [$capacity, $explicit] = [count($candidates), true];
        } else {
            $candidates = $this->candidates($brand, $site, $only, $previous, $sitePages, $main);
        }
        // A page a candidate strengthens is a page of the site even past the first pages by path (an update must find it).
        $sitePages = $sitePages->merge(collect($candidates)->pluck('page')->filter())->unique('id')->values();
        if ($candidates === []) {
            $this->logRun($site, ['status' => 'no_candidates', 'candidates' => 0, 'asked' => 0, 'added' => 0, 'dropped' => []]);

            return ['status' => 'ready', 'added' => 0];
        }
        $chosen = match (true) {
            $only !== null => array_slice($candidates, 0, 1),
            $cycle !== null => $candidates,
            default => self::mix($candidates, $capacity + (int) ceil($capacity / 4)),
        };
        $taken = $previous->map(fn (Suggestion $s): string => SeoText::fold((string) $s->title))->all();
        $added = 0;
        $dropped = [];
        $pending = $chosen;
        $notes = [];
        $promptVersion = null;
        $status = 'ready';
        $message = null;
        for ($round = 0; $round < 2 && $pending !== [] && $added < $capacity; $round++) {
            $result = $this->ai->run(new WeeklyContentAgent, $this->pack($brand, $pending, $notes, $previous, $sitePages, $main), 240, $only === null ? 'weekly'.($round > 0 ? '-again' : '') : null);
            if ($result['status'] !== 'ready') {
                $status = $result['status'];
                $message = $result['message'] ?? null;
                break;
            }
            $promptVersion = $result['prompt_version_id'];
            $byId = collect((array) ($result['data']['items'] ?? []))->filter(fn ($i): bool => is_array($i) && is_int($i['candidate_id'] ?? null))->keyBy('candidate_id');
            $retry = [];
            $notes = [];
            foreach ($pending as $candidate) {
                if ($added >= $capacity) {
                    break;
                }
                $item = $byId->get($candidate['id']);
                $reason = $item === null ? 'AI bu konuyu yazmadı' : $this->storeCandidate($brand, $site, $sitePages, $candidate + ($cycle !== null ? ['cycle' => now('Europe/Istanbul')->toDateString()] : []), $item, $taken, $explicit ? $main : null, $promptVersion);
                if ($reason === null) {
                    $added++;

                    continue;
                }
                if ($round === 0 && $item !== null && ! in_array($reason, ['aynı başlık zaten var', self::PAGE_TAKEN, self::SAME_SUBJECT], true)) {
                    $retry[] = $candidate;
                    $notes[$candidate['id']] = ['rejected_title' => (string) ($item['title'] ?? ''), 'why' => $reason];

                    continue;
                }
                $dropped[$reason] = ($dropped[$reason] ?? 0) + 1;
            }
            $pending = $retry;
        }
        foreach ($pending as $candidate) {
            if ($status !== 'ready' && isset($notes[$candidate['id']])) {
                $dropped[$notes[$candidate['id']]['why']] = ($dropped[$notes[$candidate['id']]['why']] ?? 0) + 1;
            }
        }
        $this->logRun($site, ['status' => $status, 'candidates' => count($candidates), 'asked' => count($chosen), 'added' => $added, 'dropped' => $dropped, 'message' => $message]);

        return ['status' => $added > 0 ? 'ready' : $status, 'added' => $added];
    }

    /**
     * Haftalık döngü (yakup, 2026-10-16): the week's new articles from the empty places of the active services' topic
     * maps, in writing order, and its updates from their weak places; what the maps cannot give is filled from the
     * evidenced candidates of the same services (new) or of the whole site (updates). Null while a map is still being
     * drawn by Claude (the job comes back with the answer).
     *
     * @param  Collection<int, Suggestion>  $previous
     * @param  Collection<int, Page>  $sitePages
     * @param  array{new: int, update: int}  $cycle
     * @return list<array<string, mixed>>|null
     */
    private function cycleCandidates(Brand $brand, DigitalAsset $site, Collection $previous, Collection $sitePages, string $main, array $cycle): ?array
    {
        $topics = app(ContentTopics::class);
        $active = [];
        for ($round = 0; $round < 4; $round++) {
            $active = $topics->activeServices($site, $brand);
            foreach ($active as $serviceId) {
                if ($topics->ensureMap($site, $brand, $serviceId)['status'] === 'queued') {
                    return null;
                }
            }
            $topics->sync($site);
            if ($topics->activeServices($site, $brand) === $active) {
                break;
            }
        }
        $next = $topics->next($site, $active, max(0, (int) $cycle['new']), max(0, (int) $cycle['update']));
        $rows = ContentTopics::mainRows($site)->load(['cluster.mainQuery', 'cluster.service.primaryName'])->keyBy('cluster_id');
        $pages = Page::query()->whereIn('id', $next['update']->pluck('page_id')->filter()->all() ?: [0])->get(['id', 'url', 'title', 'path', 'language', 'category', 'wp_post_type'])->keyBy('id');
        $out = [];
        foreach (['new' => $next['new'], 'update' => $next['update']] as $kind => $list) {
            foreach ($list as $topic) {
                $page = $kind === 'update' ? $pages->get((int) $topic->page_id) : null;
                if ($kind === 'update' && $page === null) {
                    continue;
                }
                $out[] = ['key' => 't:'.$topic->id, 'source' => 'topic', 'kind' => $kind, 'row' => $rows->get((int) $topic->cluster_id), 'query' => $topic->question,
                    'page' => $page, 'angle_hint' => $kind === 'update' ? 'update' : (ContentTopic::ANGLES[$topic->type] ?? null), 'score' => (float) $topic->priority,
                    'topic_id' => (int) $topic->id, 'topic_type' => $topic->type, 'topic_title' => $topic->title,
                    'evidence' => (array) ($topic->evidence ?: [['kind' => 'topic', 'value' => ContentTopic::TYPE_LABELS[$topic->type] ?? $topic->type, 'source' => 'konu haritası']])];
            }
        }
        $short = ['new' => (int) $cycle['new'] - $next['new']->count(), 'update' => (int) $cycle['update'] - collect($out)->where('kind', 'update')->count()];
        if ($short['new'] > 0 || $short['update'] > 0) {
            $taken = collect($out)->pluck('page')->filter()->pluck('id')->map(fn ($id): int => (int) $id)->flip()->all();
            foreach ($this->candidates($brand, $site, null, $previous, $sitePages, $main) as $candidate) {
                $kind = $candidate['kind'];
                $service = $candidate['row']?->cluster?->service_id;
                if ($short[$kind] <= 0 || ($kind === 'new' && ($service === null || ! in_array((int) $service, $active, true)))
                    || ($candidate['page'] !== null && isset($taken[(int) $candidate['page']->id]))) {
                    continue;
                }
                $out[] = $candidate;
                $short[$kind]--;
            }
        }
        $clusterIds = collect($out)->map(fn (array $c): ?int => $c['row']?->cluster_id !== null ? (int) $c['row']->cluster_id : null)->filter()->unique()->values()->all();
        $queries = $this->clusterQueries($clusterIds);
        foreach ($out as $i => &$candidate) {
            $candidate['id'] = $i + 1;
            $candidate['queries'] = $candidate['row'] !== null ? ($queries[(int) $candidate['row']->cluster_id] ?? []) : [];
        }
        unset($candidate);

        return $out;
    }

    public static function lastRunKey(int $siteId): string
    {
        return 'content-pool:last-run:'.$siteId;
    }

    /**
     * The last pool run of a site: when, how many candidates the data gave, how many were asked, added and why the rest
     * were dropped (shown on the content line instead of a silent pause).
     *
     * @return array{at: string, status: string, candidates: int, asked: int, added: int, dropped: array<string, int>}|null
     */
    public static function lastRun(int $siteId): ?array
    {
        $run = Cache::get(self::lastRunKey($siteId));

        return is_array($run) ? $run : null;
    }

    /** @param  array{status: string, candidates: int, asked: int, added: int, dropped: array<string, int>}  $run */
    private function logRun(DigitalAsset $site, array $run): void
    {
        arsort($run['dropped']);
        Cache::put(self::lastRunKey((int) $site->id), ['at' => now()->toIso8601String()] + $run, now()->addDays(30));
    }

    /**
     * The brand's ideas of the last 180 days (any status) and every idea still waiting, approved or snoozed: their titles,
     * clusters, queries and pages are never planned again.
     *
     * @return Collection<int, Suggestion>
     */
    private function previousIdeas(Brand $brand): Collection
    {
        return Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where(fn ($q) => $q->where('created_at', '>=', now()->subDays(180))->orWhereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED]))
            ->orderByDesc('id')->limit(2000)->get(['id', 'title', 'status', 'action', 'cluster_id', 'page_id', 'created_at']);
    }

    /**
     * Aday konular, best first. Each: id, source (query | cluster | update | ai_question), kind, cluster, query, target
     * page, score, evidence and the texts its numbers may come from.
     *
     * @param  Collection<int, BrandClusterPage>|null  $only
     * @param  Collection<int, Suggestion>  $previous
     * @param  Collection<int, Page>  $sitePages
     * @return list<array<string, mixed>>
     */
    private function candidates(Brand $brand, DigitalAsset $site, ?Collection $only, Collection $previous, Collection $sitePages, string $main): array
    {
        $usedKeys = $previous->map(fn (Suggestion $s): ?string => data_get($s->action, 'candidate'))->filter()->flip()->all();
        $usedQueries = $previous->map(fn (Suggestion $s): ?string => is_string(data_get($s->action, 'query')) ? SeoText::fold((string) data_get($s->action, 'query')) : null)->filter()->flip()->all();
        // Clusters already answered by a recent idea go last (rotation), not out: a cluster can carry more than one article.
        $recentClusters = $previous->filter(fn (Suggestion $s): bool => $s->cluster_id !== null && ($s->created_at?->gt(now()->subWeeks(8)) ?? false))
            ->pluck('cluster_id')->map(fn ($id): int => (int) $id)->flip()->all();
        $updatedPages = $previous->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->flip()->all();
        $inMain = fn ($q) => $q->where(fn ($l) => $l->whereNull('language')->orWhere('language', $main));
        $rows = $only ?? $inMain(BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName', 'page:id,url,title,path,language,category,word_count'])->where('brand_id', $brand->id)
            ->where('website_asset_id', $site->id)->whereIn('state', ['no_page', 'thin_coverage', 'weak_performance'])->where('excluded', false))
            ->orderBy('id')->limit(self::GAP_CANDIDATES)->get();
        $clusterIds = $rows->pluck('cluster_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $volumes = $this->clusterVolumes($clusterIds);
        $brandSearch = $only !== null ? [] : $this->brandSearch($site);
        $queries = $this->clusterQueries($clusterIds);
        $mainServices = BrandOffering::query()->where('brand_id', $brand->id)->where('priority', 'main')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id')->map(fn ($id): int => (int) $id)->flip()->all();
        $paid = collect($only !== null ? [] : $this->paidResults($brand))->groupBy('service_id')->map(fn (Collection $r): float => (float) $r->sum('results'))->all();
        $angleWeights = $only !== null ? [] : self::angleWeights();
        $weight = function (?int $serviceId) use ($mainServices, $paid): float {
            return 1.0 + ($serviceId !== null && isset($mainServices[$serviceId]) ? 1.0 : 0.0) + ($serviceId !== null && isset($paid[$serviceId]) ? 0.5 : 0.0);
        };
        $number = fn (float $n, int $d = 0): string => number_format($n, $d, ',', '.');
        $paidLine = fn (?int $serviceId): array => $serviceId !== null && isset($paid[$serviceId])
            ? [['kind' => 'paid', 'value' => 'Reklamda bu hizmet 30 günde '.$number($paid[$serviceId]).' sonuç getirdi', 'source' => 'Google Ads / Meta']] : [];
        $out = [];
        $seenQueries = [];

        // 1) Search Console: searches the site is shown for at position 4–20, answered by the page Google shows for them
        // (strengthen it) or by none (a new page). A search whose cluster already has a sufficient page is left alone.
        if ($only === null) {
            $striking = $this->strikingQueries($site, $brand);
            $siteRows = $inMain(BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName', 'page:id,url,title,path,language'])->where('brand_id', $brand->id)
                ->where('website_asset_id', $site->id)->where('excluded', false))->orderBy('id')->get()
                ->sortBy(fn (BrandClusterPage $r): array => [$r->language === $main ? 0 : 1, (int) $r->id])->unique('cluster_id')->keyBy('cluster_id');
            $clusterOfQuery = $this->queryClusters(array_column($striking, 'query'), $siteRows->keys()->map(fn ($id): int => (int) $id)->all());
            $pageOf = $this->rankingPages($site, array_column($striking, 'query'));
            foreach ($striking as $s) {
                $folded = SeoText::fold($s['query']);
                if (isset($usedQueries[$folded]) || isset($usedKeys['q:'.$folded]) || mb_strlen($folded) < 4) {
                    continue;
                }
                $row = isset($clusterOfQuery[$folded]) ? $siteRows->get($clusterOfQuery[$folded]) : null;
                if ($row !== null && $row->state === 'sufficient') {
                    continue;
                }
                $page = $pageOf[$s['query']] ?? null;
                // A page in another language is not this idea's page: the main-language idea is a new page instead.
                $page = $page !== null && self::inLanguage($page, $main) ? $page : null;
                if ($page !== null && isset($updatedPages[(int) $page->id])) {
                    continue;
                }
                $serviceId = $row?->cluster?->service_id !== null ? (int) $row->cluster->service_id : $s['service_id'];
                $angle = $page !== null ? 'update' : (self::isQuestion($s['query']) ? 'expert_answer' : null);
                $seenQueries[$folded] = true;
                $out[] = [
                    'key' => 'q:'.$folded, 'source' => 'query', 'kind' => $page !== null ? 'update' : 'new', 'row' => $row, 'query' => $s['query'], 'page' => $page, 'angle_hint' => $angle,
                    'score' => 1.5 * log10(1 + $s['impressions']) * ($s['position'] <= 10 ? 1.3 : 1.0) * $weight($serviceId) * ($angleWeights[$angle ?? 'decision'] ?? 1.0),
                    'evidence' => [['kind' => 'query', 'value' => '«'.$s['query'].'» 28 günde '.$number($s['impressions']).' gösterim, '.$number($s['clicks']).' tıklama'
                        .($s['position'] !== null ? ', ortalama '.$number((float) $s['position'], 1).'. sıra' : '').($page !== null ? ' · cevaplayan sayfa: '.SeoText::urlPath((string) $page->url) : ' · kendi sayfası yok'), 'source' => 'Search Console'],
                        ...$paidLine($serviceId)],
                ];
            }
        }

        // 1b) Pages whose Search Console clicks fell (last 90 days vs the 90 before): refresh them.
        if ($only === null) {
            $declining = $this->decliningPages($site, $main);
            $declineRows = $inMain(BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName'])->where('brand_id', $brand->id)
                ->where('website_asset_id', $site->id)->where('excluded', false)->whereIn('page_id', array_map(fn (array $d): int => (int) $d['page']->id, $declining) ?: [0]))
                ->orderBy('id')->get()->unique('page_id')->keyBy('page_id');
            foreach ($declining as $d) {
                $page = $d['page'];
                $key = 'd:'.$page->id;
                if (isset($updatedPages[(int) $page->id]) || isset($usedKeys[$key])) {
                    continue;
                }
                $out[] = [
                    'key' => $key, 'source' => 'update', 'kind' => 'update', 'row' => $declineRows->get((int) $page->id), 'query' => null, 'page' => $page, 'angle_hint' => 'update',
                    'score' => 1.5 * log10(1 + $d['previous'] - $d['clicks']) * ($angleWeights['update'] ?? 1.0),
                    'evidence' => [['kind' => 'decline', 'value' => SeoText::urlPath((string) $page->url).' · son 90 günde '.$number($d['clicks']).' tıklama, önceki 90 günde '
                        .$number($d['previous']).' (%'.$number(round(100 * ($d['previous'] - $d['clicks']) / max(1, $d['previous']))).' düşüş)', 'source' => 'Search Console']],
                ];
            }
        }

        // 2) Clusters without a suitable page / with thin coverage, 3) weak pages, 4) AI-assistant questions of the clusters.
        foreach ($rows as $row) {
            $cluster = $row->cluster;
            if ($cluster === null) {
                continue;
            }
            $serviceId = $cluster->service_id !== null ? (int) $cluster->service_id : null;
            $library = $volumes[(int) $row->cluster_id] ?? ['impressions' => 0, 'volume' => 0];
            $volume = $library['impressions'];
            $search = $brandSearch[(int) $row->cluster_id] ?? null;
            $demand = $weight($serviceId) * log10(1 + $library['impressions'] + $library['volume']) + 1.5 * log10(1 + ($search['impressions'] ?? 0));
            $rotation = isset($recentClusters[(int) $row->cluster_id]) ? 1 : 0;
            $parts = array_filter([
                $volume > 0 ? 'sorgu kütüphanesinde '.$number($volume).' gösterim' : null,
                $library['volume'] > 0 ? 'ayda '.$number($library['volume']).' arama (Keyword Planner)' : null,
                $search !== null && $search['impressions'] > 0 ? 'sitede 28 günde '.$number($search['impressions']).' gösterim'.($search['position'] !== null ? ', '.$number((float) $search['position'], 1).'. sıra' : '') : null,
            ]);
            $clusterLine = ['kind' => 'cluster', 'value' => $cluster->name.' · '.($parts !== [] ? implode(' · ', $parts).' · ' : '').$row->stateLabel(), 'source' => 'küme'];
            $mainQuery = (string) ($cluster->mainQuery?->text ?? ($queries[(int) $row->cluster_id][0] ?? ''));
            $isUpdate = $row->state !== 'no_page' && $row->page !== null && self::inLanguage($row->page, $main);
            if ($isUpdate && isset($updatedPages[(int) $row->page_id])) {
                continue;
            }
            $key = ($isUpdate ? 'p:'.$row->page_id.':' : 'c:').$row->cluster_id;
            // A main (★) service's thin page comes before everything else: the brand earns from it (audit K2, Avrupadent's
            // ★ All-on-4 and Gülüş tasarımı were thin while blog ideas went first).
            $mainThin = $isUpdate && $row->state === 'thin_coverage' && $serviceId !== null && isset($mainServices[$serviceId]);
            // Aşama 7: a service or location page under SiteHealthScore::THIN_WORDS words is "ana sayfayı güçlendir" work,
            // whatever its service: it goes first too.
            $mainThin = $mainThin || ($isUpdate && SiteHealthScore::isThin($row->page));
            if (! isset($usedKeys[$key])) {
                $out[] = [
                    'key' => $key, 'source' => $isUpdate ? 'update' : 'cluster', 'kind' => $isUpdate ? 'update' : 'new', 'row' => $row, 'query' => $mainQuery !== '' ? $mainQuery : null,
                    'page' => $isUpdate ? $row->page : null, 'angle_hint' => $isUpdate ? 'update' : null,
                    'tier' => $mainThin ? -1 : $rotation, 'score' => $demand * ($isUpdate ? ($row->state === 'weak_performance' ? 0.7 : 0.85) : 1.0) * ($angleWeights[$isUpdate ? 'update' : 'decision'] ?? 1.0),
                    'evidence' => [$clusterLine, ...($isUpdate ? [['kind' => 'page', 'value' => SeoText::urlPath((string) $row->page->url).' · '.$row->stateLabel()
                        .(SiteHealthScore::isThin($row->page) ? ' · yalnız '.(int) $row->page->word_count.' kelime, ana sayfa güçlendirilecek' : '')
                        .(filled($row->reason) ? ' · '.mb_substr((string) $row->reason, 0, 120) : ''), 'source' => 'site']] : []), ...$paidLine($serviceId)],
                ];
            }
            if ($only !== null || $row->state !== 'no_page') {
                continue;
            }
            $question = collect(ClusterAudit::aiQuestions($cluster, $brand))->first(fn (string $q): bool => ! isset($usedQueries[SeoText::fold($q)]) && ! isset($usedKeys['a:'.SeoText::fold($q)]));
            if ($question !== null && $demand > 0) {
                $out[] = [
                    'key' => 'a:'.SeoText::fold($question), 'source' => 'ai_question', 'kind' => 'new', 'row' => $row, 'query' => $question, 'page' => null, 'angle_hint' => 'expert_answer',
                    'tier' => $rotation, 'score' => 0.8 * $demand * ($angleWeights['expert_answer'] ?? 1.0),
                    'evidence' => [['kind' => 'ai_question', 'value' => 'AI asistanlarında sorulan: «'.$question.'»', 'source' => 'AI aramaları'], $clusterLine],
                ];
            }
        }
        // Clusters a recent idea already answers go last (rotation; yakup, 2026-10-07: pools stuck on the same clusters).
        usort($out, fn (array $a, array $b): int => [$a['tier'] ?? 0, $b['score']] <=> [$b['tier'] ?? 0, $a['score']]);
        // One new page per cluster: never while a new-page idea of the cluster waits, is approved or snoozed, and at most
        // one of its search / cluster / AI-question candidates per run (the best one).
        $newPageClusters = $previous->filter(fn (Suggestion $s): bool => $s->cluster_id !== null && data_get($s->action, 'kind') === 'new'
            && in_array($s->status, [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED], true))
            ->pluck('cluster_id')->map(fn ($id): int => (int) $id)->flip()->all();
        // One update per page per run: several searches answered by the same page make one idea for it, not ten.
        $updatePages = [];
        $out = array_values(array_filter($out, function (array $c) use (&$newPageClusters, &$updatePages): bool {
            if ($c['kind'] === 'update' && $c['page'] !== null) {
                if (isset($updatePages[(int) $c['page']->id])) {
                    return false;
                }
                $updatePages[(int) $c['page']->id] = true;

                return true;
            }
            $clusterId = $c['row']?->cluster_id !== null ? (int) $c['row']->cluster_id : null;
            if ($c['kind'] !== 'new' || $clusterId === null) {
                return true;
            }
            if (isset($newPageClusters[$clusterId])) {
                return false;
            }
            $newPageClusters[$clusterId] = true;

            return true;
        }));
        foreach ($out as $i => &$candidate) {
            $candidate['id'] = $i + 1;
            $candidate['queries'] = $candidate['row'] !== null ? ($queries[(int) $candidate['row']->cluster_id] ?? []) : [];
        }
        unset($candidate);

        return $out;
    }

    /**
     * Karışım: thin pages of main (★) services first (up to half), then about 60% new pages, 25% updates of existing
     * pages and 15% answers to AI-assistant questions, best first in each; a short share is filled from the best of the rest.
     *
     * @param  list<array<string, mixed>>  $candidates  best first
     * @return list<array<string, mixed>>
     */
    public static function mix(array $candidates, int $count): array
    {
        $groups = ['new' => [], 'update' => [], 'ai' => []];
        foreach ($candidates as $c) {
            $groups[$c['source'] === 'ai_question' ? 'ai' : ($c['kind'] === 'update' ? 'update' : 'new')][] = $c;
        }
        $quota = ['update' => (int) round($count * 0.25), 'ai' => (int) round($count * 0.15)];
        $quota['new'] = max(0, $count - $quota['update'] - $quota['ai']);
        $chosen = [];
        // Thin pages of main (★) services (tier -1) go first, up to half of the run, before the shares.
        foreach (array_slice(array_values(array_filter($candidates, fn (array $c): bool => ($c['tier'] ?? 0) < 0)), 0, (int) ceil($count / 2)) as $c) {
            $chosen[$c['id']] = $c;
            $quota['update'] = max(0, $quota['update'] - 1);
        }
        foreach ($quota as $group => $n) {
            foreach (array_slice(array_values(array_filter($groups[$group], fn (array $c): bool => ! isset($chosen[$c['id']]))), 0, $n) as $c) {
                $chosen[$c['id']] = $c;
            }
        }
        foreach ($candidates as $c) {
            if (count($chosen) >= $count) {
                break;
            }
            $chosen[$c['id']] ??= $c;
        }
        $chosen = array_values($chosen);
        usort($chosen, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_slice($chosen, 0, $count);
    }

    /**
     * What earlier articles of each angle brought (live pages' Search Console clicks in 28 days against the average of
     * all), 0.5–1.5; an angle with fewer than 3 live articles stays 1.0. Aşama 8 (2026-10-22): the floor went from 0.7 to
     * 0.5 so a topic type that brings little is pulled back harder in later plans.
     *
     * @return array<string, float>
     */
    public static function angleWeights(): array
    {
        return Cache::remember('content-pool:angle-weights', now()->addHours(12), function (): array {
            $clicks = [];
            foreach (app(ContentCoverage::class)->articleResults() as $result) {
                if ($result['live'] && $result['angle'] !== null) {
                    $clicks[$result['angle']][] = $result['clicks'];
                }
            }
            $all = array_merge(...array_values($clicks ?: [[]]));
            $average = $all !== [] ? array_sum($all) / count($all) : 0.0;
            $out = [];
            foreach ($clicks as $angle => $list) {
                if (count($list) >= 3 && $average > 0) {
                    $out[$angle] = round(max(0.5, min(1.5, (array_sum($list) / count($list)) / $average)), 2);
                }
            }

            return $out;
        });
    }

    /** A search phrased as a question (Turkish or English). */
    public static function isQuestion(string $query): bool
    {
        return (bool) preg_match('/\b(ne|neden|nasil|nasıl|nedir|kac|kaç|hangi|mi|mı|mu|mü|mudur|midir|zararli|zararlı|olur|yapilir|yapılır|what|how|why|when|which|can|does|is)\b|\?$/u', mb_strtolower($query));
    }

    /**
     * The site page Google shows most for each search (Search Console query × page, 28 days): the page that already
     * answers it. The home page answers nothing on its own; a URL the site's pages do not hold is no page.
     *
     * @param  list<string>  $queries
     * @return array<string, Page> search => page
     */
    private function rankingPages(DigitalAsset $site, array $queries): array
    {
        $w = $this->reader->window($site, 28);
        if ($queries === [] || $w['gsc'] === []) {
            return [];
        }
        $best = [];
        DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $w['gsc'])->where('search_type', 'web')->whereBetween('reporting_date', [$w['start'], $w['end']])
            ->whereIn('query', $queries)->groupBy('query', 'page')->selectRaw('query, page, sum(impressions) as impressions')
            ->orderByDesc('impressions')->orderBy('page')->get()
            ->each(function (object $r) use (&$best): void {
                $best[(string) $r->query] ??= SeoText::urlKey((string) $r->page);
            });
        $keys = array_values(array_unique(array_filter($best, fn (string $key): bool => trim(SeoText::urlPath($key), '/') !== '')));
        if ($keys === []) {
            return [];
        }
        $pages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->orderBy('id')
            ->get(['id', 'url', 'path', 'title', 'category', 'wp_post_type', 'language'])->keyBy(fn (Page $p): string => SeoText::urlKey((string) $p->url));
        $out = [];
        foreach ($best as $query => $key) {
            if (in_array($key, $keys, true) && $pages->has($key)) {
                $out[$query] = $pages->get($key);
            }
        }

        return $out;
    }

    /**
     * Folded search text => cluster id, for the given clusters.
     *
     * @param  list<string>  $texts
     * @param  list<int>  $clusterIds
     * @return array<string, int>
     */
    private function queryClusters(array $texts, array $clusterIds): array
    {
        if ($texts === [] || $clusterIds === []) {
            return [];
        }
        $wanted = collect($texts)->mapWithKeys(fn (string $t): array => [SeoText::fold($t) => true])->all();
        $out = [];
        DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds)
            ->whereIn(DB::raw('lower(q.text)'), array_map(fn (string $t): string => mb_strtolower($t), $texts))->get(['cq.cluster_id', 'q.text'])
            ->each(function (object $r) use (&$out, $wanted): void {
                $folded = SeoText::fold((string) $r->text);
                if (isset($wanted[$folded])) {
                    $out[$folded] ??= (int) $r->cluster_id;
                }
            });

        return $out;
    }

    /**
     * DATA_JSON of one writing round: the brand, the month, the chosen candidates (with what a rule found wrong in a
     * previous title of the same candidate), earlier titles and the site pages.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<int, array{rejected_title: string, why: string}>  $notes
     * @param  Collection<int, Suggestion>  $previous
     * @param  Collection<int, Page>  $sitePages
     * @return array<string, mixed>
     */
    private function pack(Brand $brand, array $candidates, array $notes, Collection $previous, Collection $sitePages, string $main): array
    {
        $clusterIds = collect($candidates)->map(fn (array $c): ?int => $c['row']?->cluster_id !== null ? (int) $c['row']->cluster_id : null)->filter()->unique()->values()->all();

        return [
            'brand' => $this->memory->contextFor($brand, [], $clusterIds)['profile'],
            'language' => $main,
            'month' => self::MONTHS[(int) now()->month].' '.now()->year,
            'brand_facts' => array_intersect_key(app(BrandFacts::class)->forPrompt($brand), array_flip(['questions', 'objections', 'praise', 'experts', 'seasonality', 'voice'])),
            'candidates' => array_map(function (array $c) use ($brand, $notes): array {
                $row = $c['row'];

                return array_filter([
                    'candidate_id' => $c['id'], 'kind' => $c['kind'], 'source' => $c['source'], 'query' => $c['query'], 'angle_hint' => $c['angle_hint'],
                    'cluster' => $row?->cluster?->name, 'service' => (string) ($row?->cluster?->service?->primaryName?->raw_label ?? ''),
                    'queries' => $c['queries'], 'gaps' => $row !== null ? array_column((array) $row->gaps, 'text') : [],
                    'ai_questions' => $row?->cluster !== null ? ClusterAudit::aiQuestions($row->cluster, $brand) : [],
                    'service_areas' => $row?->cluster !== null ? ClusterAudit::serviceAreas($row->cluster, $brand) : [],
                    'page_url' => $c['page']?->url, 'page_title' => $c['page']?->title, 'topic_type' => $c['topic_type'] ?? null, 'topic_title' => $c['topic_title'] ?? null,
                    'evidence' => array_column($c['evidence'], 'value'),
                    'previous_attempt' => $notes[$c['id']] ?? null,
                ], fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
            }, $candidates),
            'previous_titles' => $previous->take(150)->pluck('title')->values()->all(),
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title, 'category' => $p->category])->values()->all(),
        ] + Epicrisis::input($brand, 'content');
    }

    /**
     * Stores the AI's idea for one candidate, or says in plain Turkish why it was not stored.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $item
     * @param  Collection<int, Page>  $sitePages
     * @param  list<string>  $taken
     */
    private function storeCandidate(Brand $brand, DigitalAsset $site, Collection $sitePages, array $candidate, array $item, array &$taken, ?string $language, ?int $promptVersion): ?string
    {
        $title = trim((string) ($item['title'] ?? ''));
        if (($style = self::styleProblem($title)) !== null) {
            return $style === 'çok uzun' ? 'başlık çok uzun' : 'kalıp başlık ('.$style.')';
        }
        $row = $candidate['row'];
        $page = $candidate['page'];
        $item['kind'] = $candidate['kind'];
        $item['target_url'] = $page?->url;
        $numbers = implode(' ', [(string) $candidate['query'], ...$candidate['queries'], ...array_column($candidate['evidence'], 'value'), (string) $row?->cluster?->name,
            ...($row !== null ? array_column((array) $row->gaps, 'text') : [])]);

        return $this->storeItem($brand, $site, $sitePages, $item, $taken, [
            'cluster_id' => $row?->cluster_id !== null ? (int) $row->cluster_id : null, 'out_of_cluster' => false, 'language' => $language,
            'angle' => in_array($item['angle'] ?? null, array_keys(self::ANGLES), true) ? $item['angle'] : ($candidate['angle_hint'] ?? null),
            'evidence' => $candidate['evidence'], 'service' => (string) ($row?->cluster?->service?->primaryName?->raw_label ?? ''),
            'candidate' => $candidate['key'], 'source' => $candidate['source'], 'query' => $candidate['query'], 'number_text' => $numbers,
            'topic_id' => $candidate['topic_id'] ?? null, 'topic_type' => $candidate['topic_type'] ?? null, 'cycle' => $candidate['cycle'] ?? null,
            // A new content idea is never critical work (priority 3 at most urgent).
            'priority' => $candidate['score'] >= 6 ? 3 : ($candidate['score'] >= 3 ? 4 : 5),
        ], $promptVersion);
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
     * Queries the site is seen for but not near the top (position 4–20), most seen first: the reader already searches
     * this, the brand answers it weakly. A search must be seen enough for the site's size (30 impressions in 28 days,
     * less on a small site: its 75th percentile, at least 5), must not name a place outside the brand's areas, and must
     * be a library search of one of the brand's services (the library already left out places, other languages and
     * irrelevant searches; the brand's own place word is taken off before the lookup: "çankaya implant" → "implant").
     *
     * @return list<array{query: string, impressions: int, clicks: int, position: ?float, service_id: int}>
     */
    private function strikingQueries(DigitalAsset $site, Brand $brand): array
    {
        $all = collect($this->reader->queries($site, 28));
        $threshold = min(30, max(5, self::percentile($all->pluck('impressions')->map(fn ($n): int => (int) $n)->all(), 0.75)));
        $services = SiteScope::offerings($brand)->pluck('service_catalog_item_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($services === []) {
            return [];
        }
        $areaWords = array_flip(SiteScope::areaWords($brand));
        $normalizer = new QueryNormalizer;
        $lookup = [];
        foreach ($all->filter(fn (array $q): bool => $q['position'] !== null && $q['position'] >= 4 && $q['position'] <= 20 && $q['impressions'] >= $threshold) as $q) {
            $kept = [];
            foreach (explode(' ', $normalizer->normalize((string) $q['query'])) as $word) {
                $place = QueryNormalizer::placeBase(SeoText::fold($word));
                if ($place === null) {
                    $kept[] = $word;
                } elseif (! isset($areaWords[$place])) {
                    continue 2; // a place the brand does not serve
                }
            }
            $text = implode(' ', array_filter($kept, fn (string $w): bool => $w !== ''));
            $named = QueryNormalizer::placeIn((string) $q['query']);
            if ($text === '' || ($named !== null && array_diff(SeoText::tokens($named), array_keys($areaWords)) !== [])) {
                continue;
            }
            $lookup[QueryNormalizer::hash($text)][] = $q;
        }
        if ($lookup === []) {
            return [];
        }
        $library = [];
        foreach (array_chunk(array_keys($lookup), 500) as $hashes) {
            DB::table('queries')->whereIn('text_hash', $hashes)->whereIn('service_id', $services)->where('hidden', false)->orderBy('id')
                ->get(['text_hash', 'service_id'])->each(function (object $r) use (&$library): void {
                    $library[(string) $r->text_hash] ??= (int) $r->service_id;
                });
        }
        $out = [];
        foreach ($lookup as $hash => $queries) {
            foreach (isset($library[$hash]) ? $queries : [] as $q) {
                $out[] = ['query' => (string) $q['query'], 'impressions' => (int) $q['impressions'], 'clicks' => (int) $q['clicks'], 'position' => $q['position'], 'service_id' => $library[$hash]];
            }
        }
        usort($out, fn (array $a, array $b): int => [$b['impressions'], $a['query']] <=> [$a['impressions'], $b['query']]);

        return array_slice($out, 0, self::STRIKING_QUERIES);
    }

    /**
     * Main-language pages of the site that lost at least DECAY_DROP of their Search Console clicks (last 90 days vs the
     * 90 before, from at least DECAY_MIN_PREVIOUS), most clicks lost first.
     *
     * @return list<array{page: Page, clicks: int, previous: int}>
     */
    public function decliningPages(DigitalAsset $site, string $main): array
    {
        try {
            $rows = app(SitePagesReader::class)->rows($site, 90);
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
        $falling = collect($rows)->filter(fn (array $r): bool => $r['page_id'] !== null && $r['prev_clicks'] >= self::DECAY_MIN_PREVIOUS
            && $r['clicks'] <= $r['prev_clicks'] * (1 - self::DECAY_DROP))
            ->sortByDesc(fn (array $r): int => $r['prev_clicks'] - $r['clicks'])->take(self::DECAY_PAGES * 2);
        $pages = Page::query()->whereIn('id', $falling->pluck('page_id')->all() ?: [0])->where('website_asset_id', $site->id)
            ->get(['id', 'url', 'title', 'path', 'language', 'category', 'word_count', 'wp_post_type'])->keyBy('id');
        $out = [];
        foreach ($falling as $r) {
            $page = $pages->get((int) $r['page_id']);
            if ($page !== null && self::inLanguage($page, $main) && trim(SeoText::urlPath((string) $page->url), '/') !== '') {
                $out[] = ['page' => $page, 'clicks' => (int) $r['clicks'], 'previous' => (int) $r['prev_clicks']];
            }
        }

        return array_slice($out, 0, self::DECAY_PAGES);
    }

    /**
     * The value below which the given share of the numbers falls (nearest rank); 0 for none.
     *
     * @param  list<int>  $numbers
     */
    private static function percentile(array $numbers, float $share): int
    {
        if ($numbers === []) {
            return 0;
        }
        sort($numbers);

        return $numbers[max(0, (int) ceil($share * count($numbers)) - 1)];
    }

    /**
     * Services that bring the brand results on Google Ads / Meta (30 days): where content pays off first.
     *
     * @return list<array{service_id: int, service: string, channel: string, result_type: string, results: float}>
     */
    private function paidResults(Brand $brand): array
    {
        if (! SchemaState::hasTable('ad_service_stats')) {
            return [];
        }
        $rows = DB::table('ad_service_stats')->where('brand_id', $brand->id)->whereNotNull('service_id')->where('results', '>', 0)->orderByDesc('results')->limit(20)
            ->get(['service_id', 'channel', 'result_type', 'results']);
        $names = MetaDesk::serviceNames($rows->pluck('service_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $rows->map(fn (object $r): array => ['service_id' => (int) $r->service_id, 'service' => $names[(int) $r->service_id] ?? '', 'channel' => (string) $r->channel,
            'result_type' => (string) $r->result_type, 'results' => (float) $r->results])->all();
    }

    /**
     * Demand of each cluster in the query library: impressions of its visible queries and their monthly Keyword Planner
     * searches (a brand without Search Console still has the second).
     *
     * @param  list<int>  $clusterIds
     * @return array<int, array{impressions: int, volume: int}>
     */
    private function clusterVolumes(array $clusterIds): array
    {
        return DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds ?: [0])->where('q.hidden', false)
            ->groupBy('cq.cluster_id')->selectRaw('cq.cluster_id, sum(q.impressions) as impressions, sum(coalesce(q.volume, 0)) as volume')->get()
            ->mapWithKeys(fn (object $r): array => [(int) $r->cluster_id => ['impressions' => (int) $r->impressions, 'volume' => (int) $r->volume]])->all();
    }

    /**
     * "Keşfet": out-of-cluster content opportunities from the brand's own queries. Inside RunSiteOperationJob the call
     * may wait for Claude over MCP (status queued); the job runs again with the answer, found by its slot although the
     * brand's queries moved meanwhile.
     *
     * @return array{status: string, added: int} status: ready | queued | not_operational | no_queries | no_provider | error
     */
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
        ], 240, 'discovery');
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
            ], $result['prompt_version_id']) === null;
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
        $pattern = self::urlPattern($site, $sitePages);
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
        $pattern = self::urlPattern($site, $sitePages);
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
        if ($translation) {
            return $this->translate($suggestion, $site, $brand, $cluster, $sitePages, $language);
        }
        $terms = ForbiddenTerms::forBrand($brand);
        // Every input the writer copies loses its forbidden phrases first ("En iyi … seçerken" → "… seçerken"); repeated lines go once.
        $scrub = fn (array $lines): array => array_values(array_unique(array_filter(array_map(fn ($line): string => $terms->scrub((string) $line), $lines), fn (string $l): bool => $l !== '')));
        $text = fn (mixed $value): ?string => ($clean = $terms->scrub(trim((string) $value))) !== '' ? $clean : null;
        // What only this business can say (experts, branches, what its patients ask, praise and complaints): the writer must use some of it.
        $scrubFacts = fn (array $facts): array => array_filter(array_map(fn (array $lines): array => array_slice($scrub($lines), 0, 12),
            array_intersect_key($facts, array_flip(['experts', 'regions', 'questions', 'objections', 'praise']))));
        $questions = $scrub((array) ($action['questions'] ?? []));
        $angle = $text($action['angle'] ?? null);
        $reason = $text($suggestion->reason);
        $recipe = isset($action['recipe']) ? $scrub(array_map(fn (array $s): string => trim(($s['where'] ?? '') !== '' ? $s['where'].': '.$s['action'] : (string) ($s['action'] ?? '')), (array) $action['recipe'])) : [];
        $recipeSeo = (array) ($action['recipe_seo'] ?? []);
        $kind = ($action['kind'] ?? null) === 'update' ? 'update' : 'new';
        $benchmarks = $cluster !== null ? app(ClusterBenchmarks::class)->for($cluster, (int) $brand->id) : [];
        // The article answers its own search (the idea's query), not the whole cluster (yakup, 2026-10-15).
        $focus = $text($action['query'] ?? null) ?? $text($cluster?->mainQuery?->text);
        $format = ContentFormat::for((string) $suggestion->title, $focus, (string) ($action['page_type'] ?? 'blog'), is_string($action['angle'] ?? null) ? $action['angle'] : null, $benchmarks);
        $map = $cluster !== null ? app(ContentMap::class)->forWriter($brand, $site, $cluster, $suggestion) : [];
        $input = [
            'plan' => ['title' => $terms->scrub((string) $suggestion->title), 'page_type' => $action['page_type'] ?? 'blog', 'outline' => $scrub((array) ($action['outline'] ?? [])),
                'questions' => $questions, 'target_url' => $action['target_url'] ?? null, 'kind' => $kind,
                'format' => ['type' => $format['label'], 'words_min' => $format['words'][0], 'words_max' => $format['words'][1], 'shape' => $format['shape']]]
                + array_filter(['focus_query' => $focus, 'reason' => $reason !== $angle ? $reason : null, 'angle' => $angle, 'target_queries' => $scrub((array) ($action['target_queries'] ?? [])),
                    'main_page_url' => $action['main_page_url'] ?? ($kind === 'new' ? data_get($map, 'main_page.url') : null),
                    'recipe' => $recipe, 'seo_title' => $text($recipeSeo['seo_title'] ?? null), 'meta_description' => $text($recipeSeo['meta_description'] ?? null),
                    // Aşama 8: the previous version did not pass the check; this is why (fix it, keep what was right).
                    'previous_check' => $text($action['check_feedback'] ?? null)],
                    fn (mixed $v): bool => $v !== null && $v !== []),
            'cluster' => $cluster !== null ? array_filter(['name' => $cluster->name, 'main_query' => $text($cluster->mainQuery?->text), 'subtopics' => $scrub(array_filter((array) $cluster->subtopics, 'is_string')),
                'queries' => $scrub($cluster->clusterQueries->filter(fn (ClusterQuery $q): bool => $q->searchQuery !== null && ! $q->searchQuery->hidden)
                    ->sortByDesc(fn (ClusterQuery $q): int => (int) $q->searchQuery->impressions)->map(fn (ClusterQuery $q): string => (string) $q->searchQuery->text)->take(30)->all()),
                // Questions already in the plan are not sent twice.
                'ai_questions' => array_values(array_diff($scrub(ClusterAudit::aiQuestions($cluster, $brand)), $questions)), 'service_areas' => ClusterAudit::serviceAreas($cluster, $brand) ?: null,
                'benchmarks' => $benchmarks ?: null, 'map' => $map ?: null],
                fn (mixed $v): bool => $v !== null && $v !== []) : null,
            'brand' => $context['profile'], 'brand_facts' => $scrubFacts(app(BrandFacts::class)->forPrompt($brand)) ?: null,
            'notes' => $context['notes'], 'standards' => $context['standards'], 'related_pages' => [...$context['pages'], ...$context['related_pages']],
            'language' => $language,
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values()->all(),
            'forbidden' => $terms->phrases(),
        ] + Epicrisis::input($brand, 'content');
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        // The numbers of the plan and the cluster (title, questions, queries: "20'lik diş") are the writer's own data, and
        // so are the facts of the brand's epikriz ("10 yıl garanti").
        $evidence->addNumbersFrom(['plan' => $input['plan'], 'cluster' => $input['cluster'] ?? [], 'epicrisis' => data_get($input, 'brand_epicrisis.facts', [])]);
        foreach ($context['pages'] as $page) {
            $evidence->addNumbersFrom(['s' => $page['summary'], 'f' => $page['facts']]);
        }
        unset($action['article'], $action['article_blocked'], $action['article_blocked_draft']);
        $action['language'] = $language;
        $round = (int) ($action['rewrite'] ?? 0);
        // Yazı özeti (yakup, 2026-10-16): a new article first reads Google for its search (the first results, People also
        // ask, what they miss, the brand's own element); the brief steers the writer and the final check.
        if ($kind === 'new' && ! is_array($action['brief'] ?? null)) {
            $brief = $this->brief($suggestion, $site, $brand, $input, $focus, $format, $language, $map, $round);
            if ($brief === 'queued') {
                return ['status' => 'queued'];
            }
            if (is_array($brief)) {
                $action['brief'] = $brief;
                $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, ['brief' => $brief])])->save();
            }
        }
        if (is_array($action['brief'] ?? null)) {
            $input['brief'] = $action['brief'];
        }
        // A rewrite (ContentAutopilot::rework) asks under new names: an answered call of an earlier round is never reused.
        $checkRound = (int) ($action['check_rewrites'] ?? 0);
        $slot = 'article-'.$suggestion->id.($round > 0 ? '-r'.$round : '').($checkRound > 0 ? '-c'.$checkRound : '');
        $outcome = $this->writeWithFix($input, $evidence, $action, $cluster, $brand, $language, 'suggestion-'.$suggestion->id, $slot);
        $firstWords = $outcome['status'] === 'ready' ? ContentFormat::words((string) $outcome['article']['html']) : 0;
        if ($outcome['status'] === 'ready' && $outcome['attempts'] === 1 && ContentFormat::tooShort($firstWords, $format)) {
            // Too thin for its subject: written once more with the length it needs (one extra write at most); the first
            // one stays when the second is not longer or fails a rule.
            $input['fix'] = [['field' => 'html', 'phrase' => '', 'rule' => 'length', 'instruction' => 'The article has '.ContentFormat::words((string) $outcome['article']['html'])
                .' words; this subject ('.$format['label'].') needs '.$format['words'][0].'–'.$format['words'][1].'. Write it again in full depth: answer plan.focus_query completely, '
                .'cover what the first Google results and benchmarks cover, add the brand\'s own facts. No filler, no repetition.']];
            $longer = $this->writeOnce($input, $evidence, $action, $cluster, $brand, $language, 'suggestion-'.$suggestion->id, $slot.'-long');
            if ($longer['status'] === 'queued') {
                return ['status' => 'queued'];
            }
            $outcome = $longer['status'] === 'ready' && ContentFormat::words((string) $longer['article']['html']) > $firstWords ? $longer : $outcome;
        }
        if ($outcome['status'] === 'invalid' || $outcome['status'] !== 'ready' && ! isset($outcome['article'])) {
            return array_intersect_key($outcome, ['status' => 1, 'message' => 1]);
        }
        $article = $outcome['article'];
        $action['article_format'] = ['type' => $format['label'], 'intent' => $format['intent'], 'words_min' => $format['words'][0], 'words_max' => $format['words'][1],
            'words' => ContentFormat::words((string) $article['html'])];
        if ($outcome['status'] === 'blocked') {
            // Kept to read and fix by hand; never sent while blocked.
            $suggestion->forceFill(['action' => $action + ['article_blocked' => $outcome['message'], 'article_blocked_draft' => $article]])->save();

            return ['status' => 'blocked', 'message' => $outcome['message']];
        }
        $warnings = $terms->warnings(implode(' . ', [$article['title'], $article['meta_title'], $article['meta_description'], strip_tags($article['html'])]));
        $action['article_warnings'] = $warnings !== [] ? 'Uyarı (yasaklı ifade, uyar): «'.implode('», «', $warnings).'»' : null;
        $action['article_seo'] = ArticleSeoCheck::check($article, $focus, (array) ($input['cluster']['service_areas'] ?? []), SiteScope::origin($site),
            $action['article_format'], ArticleSeoCheck::brandSignals((array) ($input['brand_facts'] ?? [])), data_get($map, 'main_page.url'));
        $others = array_values(array_diff(self::siteLanguages($site), [$language]));
        // Çeviri talebe göre (yakup, 2026-10-16): a language whose pages Google has shown almost nowhere in 90 days, though
        // the site has many of them, gets no translation; it can still be translated by hand.
        $translate = ($action['kind'] ?? null) !== 'update' ? array_values(array_filter($others, fn (string $other): bool => self::languageHasDemand($site, $other))) : [];
        $others = array_values(array_diff($others, $translate));
        unset($action['translations'], $action['translations_blocked']);
        $suggestion->forceFill(['action' => array_merge($action, ['article' => $article,
            'article_note' => match (true) {
                $translate !== [] => 'Sitenin diğer dillerine ('.implode(', ', array_map('strtoupper', $translate)).') bu yazının çevirisi hazırlanıyor.'
                    .($others !== [] ? ' '.implode(', ', array_map('strtoupper', $others)).' sayfaları Google\'da neredeyse hiç görünmediği için o dile çevrilmiyor.' : ''),
                $others !== [] => 'Sitede başka dil de var ('.implode(', ', array_map('strtoupper', $others)).'); o dilin sayfaları Google\'da neredeyse hiç görünmediği için çevrilmedi. Onarım masası › Diğer işler › içerik kutusundan elle çevrilebilir.',
                default => null,
            }])])->save();
        // yakup, 2026-10-07: other languages get no ideas of their own; the written article is translated into them.
        foreach ($translate as $other) {
            SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id, 'language' => $other]);
        }

        return ['status' => 'ready'];
    }

    /**
     * The other-language version of a written article: a faithful translation of it (same headings, facts and links),
     * never a new article. Stored under `action.translations.{lang}` (or `translations_blocked` when it breaks a sector
     * rule); only that key is written, under a row lock, so translations of several languages can finish together.
     *
     * @param  Collection<int, Page>  $sitePages
     * @return array{status: string, message?: string}
     */
    private function translate(Suggestion $suggestion, DigitalAsset $site, Brand $brand, ?Cluster $cluster, Collection $sitePages, string $language): array
    {
        $source = (array) data_get($suggestion->action, 'article');
        $terms = ForbiddenTerms::forBrand($brand);
        $input = [
            'translate_from' => array_intersect_key($source, array_flip(['title', 'meta_title', 'meta_description', 'excerpt', 'html'])) + ['language' => (string) ($source['language'] ?? self::articleLanguage($suggestion, $site))],
            'language' => $language,
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values()->all(),
            'forbidden' => $terms->phrases(),
        ];
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        $evidence->addNumbersFrom(['t' => (string) ($source['title'] ?? ''), 'h' => strip_tags((string) ($source['html'] ?? ''))]);
        $outcome = $this->writeWithFix($input, $evidence, ['kind' => data_get($suggestion->action, 'kind'), 'page_type' => data_get($suggestion->action, 'page_type')],
            $cluster, $brand, $language, 'suggestion-'.$suggestion->id.'-'.$language,
            'translate-'.$suggestion->id.'-'.$language.(($round = (int) data_get($suggestion->action, 'rewrite', 0)) > 0 ? '-r'.$round : ''));
        if ($outcome['status'] === 'invalid' || $outcome['status'] !== 'ready' && ! isset($outcome['article'])) {
            return array_intersect_key($outcome, ['status' => 1, 'message' => 1]);
        }
        $blocked = $outcome['status'] === 'blocked';
        DB::transaction(function () use ($suggestion, $language, $outcome, $blocked): void {
            $fresh = Suggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
            $action = (array) $fresh->action;
            unset($action['translations'][$language], $action['translations_blocked'][$language]);
            $key = $blocked ? 'translations_blocked' : 'translations';
            $action[$key] = array_merge((array) ($action[$key] ?? []), [$language => $blocked ? $outcome['message'] : $outcome['article']]);
            $fresh->forceFill(['action' => $action])->save();
            $suggestion->setRawAttributes($fresh->getAttributes(), true);
        });

        return $blocked ? ['status' => 'blocked', 'message' => (string) $outcome['message']] : ['status' => 'ready'];
    }

    /**
     * At most two writes: when the first one breaks a sector rule, the second gets the offending phrases to rewrite.
     * Each call is named ($slot + attempt) so a run that waits for Claude gets the same answer back even when the
     * site's pages or notes changed meanwhile.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $action
     * @return array{status: string, message?: string, article?: array<string, mixed>, violations: list<array<string, mixed>>, attempts: int}
     */
    private function writeWithFix(array $input, SiteEvidence $evidence, array $action, ?Cluster $cluster, Brand $brand, string $language, string $reference, string $slot): array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $outcome = $this->writeOnce($input, $evidence, $action, $cluster, $brand, $language, $reference, $slot.'-'.$attempt);
            if ($outcome['status'] !== 'blocked' || $outcome['violations'] === [] || $attempt === 2) {
                break;
            }
            $input['fix'] = ContentComplianceGate::forPrompt($outcome['violations']);
        }

        return $outcome + ['attempts' => $attempt];
    }

    /** Pages of a language the site needs before a lack of impressions counts as no demand. */
    public const int DEMAND_PAGES = 10;

    /** Impressions (90 days) of a language's pages below which it has no demand. */
    public const int DEMAND_IMPRESSIONS = 10;

    /**
     * Whether a language of the site is searched: true unless the site has DEMAND_PAGES pages in it and Google showed
     * them fewer than DEMAND_IMPRESSIONS times in 90 days (a new language with few pages always gets translations).
     */
    public static function languageHasDemand(DigitalAsset $site, string $language): bool
    {
        $urls = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->orderBy('id')->limit(2000)->get(['id', 'url', 'path', 'language'])
            ->filter(fn (Page $p): bool => self::inLanguage($p, $language))->pluck('url')->map(fn ($u): string => (string) $u)->values();
        if ($urls->count() < self::DEMAND_PAGES || ! SchemaState::hasTable('gsc_page_daily')) {
            return true;
        }
        $variants = $urls->flatMap(fn (string $u): array => [$u, rtrim($u, '/'), rtrim($u, '/').'/'])->unique()->values()->all();

        return (int) DB::table('gsc_page_daily')->whereIn('page', $variants)->where('reporting_date', '>=', now()->subDays(90)->toDateString())->sum('impressions') >= self::DEMAND_IMPRESSIONS;
    }

    /** Google markets by the article's language (the brief reads that country's results). */
    private const array MARKETS = ['tr' => 'Türkiye', 'en' => 'United Kingdom', 'de' => 'Deutschland', 'nl' => 'Nederland', 'fr' => 'France', 'it' => 'Italia',
        'ru' => 'Россия', 'ar' => 'Saudi Arabia', 'es' => 'España', 'pl' => 'Polska', 'sv' => 'Sverige', 'da' => 'Danmark', 'no' => 'Norge'];

    /**
     * The brief of a new article (`content.brief`): Google's first results for its search and what the article must do
     * better. "queued" while Claude has not answered; null when no brief could be made (the article is written without).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $format
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>|string|null
     */
    private function brief(Suggestion $suggestion, DigitalAsset $site, Brand $brand, array $input, ?string $focus, array $format, string $language, array $map, int $round): array|string|null
    {
        $result = $this->ai->run(new ContentBriefAgent, [
            'title' => (string) $input['plan']['title'], 'focus_query' => $focus ?? (string) $input['plan']['title'], 'language' => $language,
            'market' => self::MARKETS[$language] ?? strtoupper($language),
            'format' => ['type' => $format['label'], 'words_min' => $format['words'][0], 'words_max' => $format['words'][1]],
            'queries' => array_slice((array) data_get($input, 'cluster.queries', []), 0, 20), 'ai_questions' => array_slice((array) data_get($input, 'cluster.ai_questions', []), 0, 10),
            'brand' => $input['brand'], 'brand_facts' => $input['brand_facts'],
            'cluster_pages' => array_values(array_filter([data_get($map, 'main_page'), ...(array) ($map['other_pages'] ?? [])])),
        ] + array_intersect_key($input, ['brand_epicrisis' => true]), 300, 'brief-'.$suggestion->id.($round > 0 ? '-r'.$round : ''));
        if ($result['status'] === 'queued') {
            return 'queued';
        }
        if ($result['status'] !== 'ready') {
            return null;
        }
        $terms = ForbiddenTerms::forBrand($brand);
        $data = (array) $result['data'];
        $lines = fn (mixed $list, int $max): array => array_values(array_slice(array_filter(array_map(fn ($l): string => $terms->scrub(mb_substr(trim((string) $l), 0, 240)), (array) $list), fn (string $l): bool => $l !== ''), 0, $max));
        $brief = [
            'searched' => (bool) ($data['searched'] ?? false), 'intent' => $terms->scrub(mb_substr(trim((string) ($data['intent'] ?? '')), 0, 300)),
            'format' => mb_substr(trim((string) ($data['format'] ?? '')), 0, 200),
            'top_results' => array_values(array_slice(array_filter(array_map(fn ($r): ?array => is_array($r) && filled($r['url'] ?? null) && str_starts_with((string) $r['url'], 'http')
                ? ['title' => mb_substr((string) ($r['title'] ?? ''), 0, 160), 'url' => mb_substr((string) $r['url'], 0, 400), 'covers' => $lines($r['covers'] ?? [], 8)] : null,
                (array) ($data['top_results'] ?? []))), 0, 5)),
            'people_also_ask' => $lines($data['people_also_ask'] ?? [], 8), 'must_cover' => $lines($data['must_cover'] ?? [], 10), 'gaps' => $lines($data['gaps'] ?? [], 5),
            'brand_element' => $terms->scrub(mb_substr(trim((string) ($data['brand_element'] ?? '')), 0, 300)), 'outline' => $lines($data['outline'] ?? [], 10),
            'at' => now()->toIso8601String(),
        ];

        return $brief['must_cover'] !== [] || $brief['outline'] !== [] ? $brief : null;
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
    private function writeOnce(array $input, SiteEvidence $evidence, array $action, ?Cluster $cluster, Brand $brand, string $language, string $reference, ?string $slot = null): array
    {
        $result = $this->ai->run(new WriteArticleAgent, $input, 600, $slot);
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
     * $postDate (İçerik otopilotu): the drafts carry that date, scheduled when it is still ahead and the site allows it.
     */
    public function sendDraft(Suggestion $suggestion, User $user, ?CarbonImmutable $postDate = null): int
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        if (! is_array($action['article'] ?? null) || $site === null) {
            throw ValidationException::withMessages(['write' => 'Önce "Taslak hazırla".']);
        }
        if (($action['kind'] ?? null) === 'update') {
            return $this->sendPageUpdate($suggestion, $user, $site, $action);
        }
        $sent = array_values((array) ($action['sent_languages'] ?? []));
        // The featured image: the one the operator picked (or "görselsiz"), else the best of the site's own media.
        if (! array_key_exists('image', $action)) {
            $action['image'] = app(ArticleImagePicker::class)->candidates($suggestion, $site, 1)[0] ?? null;
        }
        $image = ['featured_media_id' => (int) data_get($action, 'image.media_id') ?: null]
            + ($postDate !== null ? ['post_date' => $postDate, 'schedule' => $postDate->isFuture()] : []);
        $pending = array_filter((array) ($action['translations'] ?? []), fn ($article, $lang): bool => is_array($article) && ! in_array($lang, $sent, true), ARRAY_FILTER_USE_BOTH);
        $drafts = array_map(fn (array $article): ArticleDraft => ArticleDraft::fromArray($image + $article), array_values($pending));
        if (! isset($action['article_write_id'])) {
            $source = ArticleDraft::fromArray($image + $action['article']);
            $write = app(ExternalWriteService::class)->requestArticleDrafts($user, $site, $source, $drafts);
            $action = array_merge($action, ['article_write_id' => $write->id, 'sent_languages' => [$source->language ?? self::articleLanguage($suggestion, $site), ...array_keys($pending)]]);
        } elseif ($drafts !== []) {
            $write = app(ExternalWriteService::class)->requestArticleDrafts($user, $site, $drafts[0], array_slice($drafts, 1));
            $action = array_merge($action, ['sent_languages' => [...$sent, ...array_keys($pending)],
                'translation_write_ids' => [...(array) ($action['translation_write_ids'] ?? []), (int) $write->id]]);
        } else {
            throw ValidationException::withMessages(['write' => 'Bu yazı zaten gönderildi; gönderilecek yeni dil yok.']);
        }
        unset($action['send_failed']);
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'action' => $action])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı', 'WordPress taslağı');

        return (int) $write->id;
    }

    /**
     * A WordPress draft write of an idea that failed (or reached WordPress only in some languages): the languages that
     * did not get there are no longer counted as sent, so the idea returns to "send" (and to the Günlük gönderim strip)
     * with the reason instead of showing "Taslak gönderildi" for a post that does not exist (Avrupadent, 2026-10-14:
     * 18 articles stood as drafts in MoxDOP while WordPress had none of them). Nothing is sent again here: the next
     * send stays the operator's click.
     */
    public function draftFailed(ExternalWriteAction $write): void
    {
        $suggestionId = $write->suggestion_id
            ?? (preg_match('/^suggestion-(\d+)$/', (string) data_get($write->request_payload, 'reference'), $m) === 1 ? (int) $m[1] : null);
        $suggestion = $suggestionId !== null ? Suggestion::query()->whereKey($suggestionId)->where('action_type', SiteSuggestionTypes::CONTENT)->first() : null;
        if ($suggestion === null) {
            return;
        }
        $action = (array) $suggestion->action;
        $writeId = (int) $write->id;
        $isSource = (int) ($action['article_write_id'] ?? 0) === $writeId;
        $translationIds = array_map('intval', (array) ($action['translation_write_ids'] ?? []));
        if (! $isSource && ! in_array($writeId, $translationIds, true)) {
            return;
        }
        $error = trim((string) $write->error) ?: (string) (collect((array) data_get($write->result, 'posts', []))->first(fn (mixed $p): bool => is_array($p) && ! ($p['ok'] ?? false))['error'] ?? 'WordPress taslağı oluşmadı.');
        $failedLanguages = $write->status === 'failed'
            ? array_values(array_filter((array) data_get($write->request_payload, 'languages', []), 'is_string'))
            : array_values(array_filter(array_map(fn (mixed $p): ?string => is_array($p) && ! ($p['ok'] ?? false) ? ($p['language'] ?? null) : null, (array) data_get($write->result, 'posts', [])), 'is_string'));
        // A language another write of the idea did put on WordPress (sent again later) stays sent.
        $others = array_values(array_diff(array_filter([(int) ($action['article_write_id'] ?? 0), ...$translationIds]), [$writeId]));
        $reached = ExternalWriteAction::query()->whereIn('id', $others ?: [0])->whereIn('status', ['succeeded', 'partial'])->get(['id', 'result'])
            ->flatMap(fn (ExternalWriteAction $w): array => array_map(fn (mixed $p): ?string => is_array($p) && ($p['ok'] ?? false) ? ($p['language'] ?? null) : null, (array) data_get($w->result, 'posts', [])))
            ->filter(fn (mixed $l): bool => is_string($l))->all();
        $failedLanguages = array_values(array_diff($failedLanguages, $reached));
        $failure = ['write_id' => $writeId, 'at' => now()->toIso8601String(), 'error' => mb_substr($error, 0, 300)];
        if ($isSource && $write->status === 'failed') {
            unset($action['article_write_id'], $action['page_update'], $action['translation_write_ids']);
            $action['sent_languages'] = [];
        } else {
            $action['sent_languages'] = array_values(array_diff((array) ($action['sent_languages'] ?? []), $failedLanguages));
            if (! $isSource && $write->status === 'failed') {
                $action['translation_write_ids'] = array_values(array_diff($translationIds, [$writeId]));
            }
        }
        $action['send_failed'] = $failure;
        $suggestion->forceFill(['action' => $action])->save();
    }

    /**
     * Once (deploy, 2026-10-19): ideas whose WordPress draft write failed before draftFailed existed go back to "send".
     *
     * @return int ideas released
     */
    public function releaseFailedDrafts(): int
    {
        $released = 0;
        Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->whereNotNull('action->article_write_id')->orderBy('id')->get()
            ->each(function (Suggestion $idea) use (&$released): void {
                $ids = array_values(array_filter([(int) data_get($idea->action, 'article_write_id'), ...array_map('intval', (array) data_get($idea->action, 'translation_write_ids', []))]));
                $failed = ExternalWriteAction::query()->whereIn('id', $ids ?: [0])->where('status', 'failed')->orderByDesc('id')->get();
                foreach ($failed as $write) {
                    $this->draftFailed($write);
                    $idea->refresh();
                }
                $released += $failed->isNotEmpty() && data_get($idea->action, 'article_write_id') === null ? 1 : 0;
            });

        return $released;
    }

    /**
     * Güncelleme (yakup, 2026-10-12: "bu içeriğin içerisine mi yazılacak?"): the strengthened page's new version goes to
     * WordPress as a draft copy of that page (ADR-070), not as a separate post; "Sayfaya uygula" puts it live.
     *
     * @param  array<string, mixed>  $action
     */
    private function sendPageUpdate(Suggestion $suggestion, User $user, DigitalAsset $site, array $action): int
    {
        if (isset($action['article_write_id'])) {
            throw ValidationException::withMessages(['write' => 'Sayfanın yeni sürümü zaten WordPress\'te taslak.']);
        }
        $page = $suggestion->page_id !== null ? Page::query()->find($suggestion->page_id) : null;
        // A correction of a sent article (ResultCorrections) names the WordPress post itself: it may not be collected yet.
        $postId = $page?->wp_post_id ?? ((int) ($action['wp_post_id'] ?? 0) ?: null);
        if ($postId === null) {
            throw ValidationException::withMessages(['write' => 'Güncellenecek sayfa WordPress\'ten gelmiyor; metni sayfaya elle taşı.']);
        }
        $article = (array) $action['article'];
        $write = app(ExternalWriteService::class)->requestContentDraft($user, $site, ['object_id' => (int) $postId,
            'title' => (string) ($article['title'] ?? $suggestion->title), 'html' => (string) ($article['html'] ?? ''), 'reference' => 'suggestion-'.$suggestion->id], $suggestion);
        $action = array_merge($action, ['article_write_id' => (int) $write->id, 'page_update' => true,
            'sent_languages' => [(string) ($article['language'] ?? self::articleLanguage($suggestion, $site))]]);
        unset($action['send_failed']);
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'action' => $action])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı', 'Sayfanın yeni sürümü WordPress taslağı');

        return (int) $write->id;
    }

    /**
     * "Sayfaya uygula": the draft copy (possibly edited in WordPress) replaces the live page's title and text, and the
     * article's SEO title and description go to the page (ADR-070, Admin click, logged, undoable).
     *
     * @return list<int> write ids
     */
    public function applyPageUpdate(Suggestion $suggestion, User $user): array
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        $draft = ExternalWriteAction::query()->whereKey((int) ($action['article_write_id'] ?? 0))->where('action', ExternalWriteAction::ACTION_CONTENT_DRAFT)->first();
        if (($action['kind'] ?? null) !== 'update' || $site === null || $draft === null) {
            throw ValidationException::withMessages(['write' => 'Önce sayfanın yeni sürümünü WordPress\'e taslak olarak gönder.']);
        }
        if (isset($action['page_apply_write_id'])) {
            throw ValidationException::withMessages(['write' => 'Yeni sürüm sayfaya zaten uygulandı.']);
        }
        if ($draft->status !== 'succeeded') {
            throw ValidationException::withMessages(['write' => 'WordPress taslak kopyası henüz hazır değil.']);
        }
        $writes = app(ExternalWriteService::class);
        $ids = [(int) $writes->requestContentApply($user, $site, (int) ($draft->result['post_id'] ?? 0), $suggestion)->id];
        $page = $suggestion->page_id !== null ? Page::query()->find($suggestion->page_id) : null;
        $article = (array) ($action['article'] ?? []);
        $fixes = [];
        foreach (['meta_title' => 'seo_title', 'meta_description' => 'seo_description'] as $field => $type) {
            if ($page?->wp_post_id !== null && filled($article[$field] ?? null)) {
                $fixes[] = ['type' => $type, 'object_id' => (int) $page->wp_post_id, 'reference' => 'suggestion-'.$suggestion->id.'-'.$type, 'value' => (string) $article[$field]];
            }
        }
        if ($fixes !== []) {
            $ids[] = (int) $writes->requestSiteFixes($user, $site, $fixes, $suggestion)->id;
        }
        $suggestion->forceFill(['status' => Suggestion::APPLIED, 'applied_at' => now(), 'action' => array_merge($action, ['page_apply_write_id' => $ids[0], 'page_apply_write_ids' => $ids])])->save();
        app(OutcomeTracker::class)->apply($suggestion, $user);
        $this->memory->recordDecision($suggestion, 'onaylandı', 'Sayfanın yeni sürümü canlıya alındı');

        return $ids;
    }

    /**
     * @param  Collection<int, Page>  $sitePages
     * @param  array<string, mixed>  $item
     * @param  list<string>  $taken  folded titles already planned
     * @param  array<string, mixed>  $extra  number_text: data the idea's numbers may come from (its queries, evidence)
     * @return string|null why it was not stored (plain Turkish), null when stored
     */
    private function storeItem(Brand $brand, DigitalAsset $site, Collection $sitePages, array $item, array &$taken, array $extra, ?int $promptVersionId): ?string
    {
        $compliance = BriefCompliance::forBrand($brand);
        preg_match_all('/\d+(?:[.,]\d+)?/u', (string) ($extra['number_text'] ?? ''), $dataNumbers);
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all(), $dataNumbers[0]);
        $title = trim((string) ($item['title'] ?? ''));
        $folded = SeoText::fold($title);
        $pageType = in_array($item['page_type'] ?? null, self::PAGE_TYPES, true) ? $item['page_type'] : 'blog';
        $problem = match (true) {
            mb_strlen($title) < 5 => 'başlık boş',
            in_array($folded, $taken, true) => 'aynı başlık zaten var',
            ! $compliance->isCompliant($title) => 'sektörde yasaklı ifade',
            ! $evidence->grounded($title) => 'veride olmayan sayı ya da adres',
            default => null,
        };
        if ($problem !== null) {
            return $problem;
        }
        $lines = fn (mixed $list, int $max): array => array_values(array_slice($compliance->filter(array_values(array_filter(array_map(fn ($l): string => mb_substr(trim((string) $l), 0, 200), (array) $list),
            fn (string $l): bool => $l !== '' && $evidence->grounded($l)))), 0, $max));
        $kind = ($item['kind'] ?? 'new') === 'update' ? 'update' : 'new';
        $target = null;
        $pageId = null;
        $mapNote = null;
        // Küme haritası (yakup, 2026-10-15): a page of the site that already answers the subject is strengthened instead
        // of getting a competitor; another idea with the same subject keeps it.
        if ($kind === 'new') {
            $map = app(ContentMap::class);
            $query = is_string($extra['query'] ?? null) ? $extra['query'] : null;
            if (($owner = $map->owner($site, $query, $title)) !== null) {
                [$kind, $item['target_url'], $sitePages] = ['update', (string) $owner->url, $sitePages->push($owner)->unique('id')->values()];
                $mapNote = 'Sitede bu konuyu işleyen sayfa var; yeni yazı yerine o sayfa güçlendirilecek.';
                $extra['angle'] = 'update';
            } elseif ($map->sibling($site, $query, $title) !== null) {
                return self::SAME_SUBJECT;
            }
        }
        if ($kind === 'update') {
            $page = $sitePages->first(fn (Page $p): bool => SeoText::urlKey((string) $p->url) === SeoText::urlKey((string) ($item['target_url'] ?? '')));
            if ($page === null) {
                return 'güncellenecek sayfa sitede yok'; // an update must name a real page of the site
            }
            if (! self::inLanguage($page, (string) ($extra['language'] ?? SiteScope::primaryLanguage($site) ?? ''))) {
                return 'güncellenecek sayfa başka dilde'; // a Turkish idea never rewrites the /de/ page (opcklinik.com, 2026-10-12)
            }
            [$target, $pageId] = [(string) $page->url, (int) $page->id];
            if (Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)->where('page_id', $pageId)
                ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED])->exists()) {
                return self::PAGE_TAKEN; // one waiting update per page
            }
        } else {
            $pattern = self::urlPattern($site, $sitePages);
            $target = $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify($title), (string) ($extra['service'] ?? ''));
        }
        $reason = trim((string) ($item['reason'] ?? ''));
        $fingerprint = hash('sha256', implode('|', [$brand->id, 'content', $folded]));
        if (Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->exists()) {
            return 'aynı başlık zaten var';
        }
        Suggestion::query()->create([
            'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => $fingerprint, 'material_hash' => hash('sha256', $folded),
            'title' => mb_substr($title, 0, 160), 'reason' => mb_substr($evidence->grounded($reason) ? $reason : '', 0, 240), 'priority' => (int) ($extra['priority'] ?? 3),
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
                'candidate' => $extra['candidate'] ?? null, 'source' => $extra['source'] ?? null, 'query' => $extra['query'] ?? null, 'map_note' => $mapNote,
                'topic_id' => $extra['topic_id'] ?? null, 'topic_type' => $extra['topic_type'] ?? null, 'cycle' => $extra['cycle'] ?? null,
            ], fn (mixed $v): bool => $v !== null),
        ]);
        $taken[] = $folded;

        return null;
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
        // Main-language pages first: ordered by path alone, a site's /de/ and /en/ pages filled the 300 before its own.
        return Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)
            ->orderByRaw('CASE WHEN language = ? THEN 0 ELSE 1 END', [SiteScope::primaryLanguage($site) ?? ''])->orderBy('path')->limit(300)
            ->get(['id', 'url', 'path', 'title', 'category', 'wp_post_type', 'language']);
    }

    /**
     * Whether the page is in the language (or says none): its detected language, else the language segment of its
     * address ("/de/…"). Pages without a detected language under /de/ counted as Turkish ones (opcklinik.com, 2026-10-12).
     */
    public static function inLanguage(Page $page, ?string $language): bool
    {
        if ($language === null || $language === '') {
            return true;
        }
        $own = strtolower(substr((string) $page->language, 0, 2));
        if ($own === '') {
            $first = explode('/', trim((string) ($page->path ?: parse_url((string) $page->url, PHP_URL_PATH)), '/'))[0];
            $own = preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $first) === 1 ? strtolower(substr($first, 0, 2)) : '';
        }

        return $own === '' || $own === strtolower(substr($language, 0, 2));
    }

    /**
     * Where a new article of the site would live, learned from the site's main-language pages only: on a site with more
     * pages under /de/ than in Turkish, a Turkish article's address must not get the /de/ prefix (opcklinik.com).
     */
    private static function urlPattern(DigitalAsset $site, Collection $sitePages): SiteUrlPattern
    {
        $main = SiteScope::primaryLanguage($site);
        $own = $main === null ? $sitePages : $sitePages->filter(fn (Page $p): bool => self::inLanguage($p, $main));

        return new SiteUrlPattern(($own->isNotEmpty() ? $own : $sitePages)
            ->map(fn (Page $p): array => ['url' => (string) $p->url, 'path' => (string) $p->path, 'cms_type' => $p->wp_post_type])->values()->all());
    }

    /** Article HTML with scripts removed, links only to site pages, blocks with invented numbers dropped and no empty heading left. */
    private function groundedHtml(string $html, SiteEvidence $evidence): string
    {
        $html = (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', fn (array $m): string => $evidence->knowsUrl($m[1]) ? $m[0] : $m[2], $html);
        $html = (string) preg_replace_callback('#<(p|li|h[2-6]|td)\b[^>]*>.*?</\1>#is', fn (array $m): string => $evidence->grounded(strip_tags($m[0])) ? $m[0] : '', $html);
        $html = ContentFormat::dropEmptySections($html);

        return trim(strip_tags($html)) === '' ? '' : trim($html);
    }
}
