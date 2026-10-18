<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\TopicMapAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\ContentSiteState;
use App\Models\ContentTopic;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandFacts;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Epicrisis\Epicrisis;
use App\Services\Meta\MetaDesk;
use App\Services\SeoTasks\SeoText;
use App\Services\Work\PageSearchStats;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konu haritası (yakup, 2026-10-16 "kümeyi bitir"): every service of a site gets a map of one main page and 8–15
 * questions (decision, process and aftercare, fears, local, seasonal, abroad), each with the page that answers it today.
 * The site writes one service at a time (two on big sites): the service worth most to the business first (demand ×
 * value × winnability × gap); its empty places become new articles in type order, its weak pages updates; once nothing
 * is left the next service starts. The map is drawn by AI (`content.topic_map`) from the brand's own data and checked
 * by rules (types, pages, titles); it is drawn again after MAP_DAYS keeping what was made of it.
 */
final class ContentTopics
{
    public const int MAP_DAYS = 90;

    /** A big site works on two services at once. */
    public const int BIG_SITE_SERVICES = 8;

    public const int BIG_SITE_CLUSTERS = 40;

    /** Topics of one map. */
    public const int MAX_TOPICS = 16;

    /** A seasonal topic is written this many weeks before its month. */
    public const array SEASON_WEEKS = [4, 6];

    public function __construct(private readonly SiteAi $ai, private readonly BrandMemoryService $memory, private readonly ContentMap $map) {}

    /**
     * The services of the site whose maps are written now: the kept ones while they still have work, then the next
     * by score. Stored on the site's content state.
     *
     * @return list<int>
     */
    public function activeServices(DigitalAsset $site, Brand $brand): array
    {
        $state = ContentSiteState::for($site);
        $scores = $this->serviceScores($site, $brand);
        $limit = self::bigSite($scores) ? 2 : 1;
        $active = array_values(array_filter(array_map('intval', (array) ($state->active_service_ids ?? [])),
            fn (int $id): bool => isset($scores[$id]) && ! $this->finished($site, $id)));
        foreach (array_keys($scores) as $serviceId) {
            if (count($active) >= $limit) {
                break;
            }
            if (! in_array($serviceId, $active, true) && ! $this->finished($site, $serviceId)) {
                $active[] = $serviceId;
            }
        }
        if ($active !== array_map('intval', (array) ($state->active_service_ids ?? []))) {
            $state->forceFill(['active_service_ids' => $active])->save();
        }

        return $active;
    }

    /**
     * The site's services with clusters, best first: demand (query library impressions + Keyword Planner volume) ×
     * business value (main service, paid results) × winnability (the site's position) × gap (clusters without a
     * sufficient page).
     *
     * @return array<int, array{score: float, clusters: int, gap: float}>
     */
    public function serviceScores(DigitalAsset $site, Brand $brand): array
    {
        $rows = self::mainRows($site);
        if ($rows->isEmpty()) {
            return [];
        }
        $clusters = $rows->pluck('cluster')->filter()->keyBy('id');
        $volumes = DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusters->keys()->all() ?: [0])->where('q.hidden', false)
            ->groupBy('cq.cluster_id')->selectRaw('cq.cluster_id, sum(q.impressions) + sum(coalesce(q.volume, 0)) as demand')->pluck('demand', 'cq.cluster_id')->all();
        $main = BrandOffering::query()->where('brand_id', $brand->id)->where(fn ($q) => $q->where('priority', 'main')->orWhere('is_priority', true))
            ->pluck('service_catalog_item_id')->map(fn ($id): int => (int) $id)->flip()->all();
        $paid = Schema::hasTable('ad_service_stats') ? DB::table('ad_service_stats')->where('brand_id', $brand->id)->where('results', '>', 0)->pluck('service_id')->map(fn ($id): int => (int) $id)->flip()->all() : [];
        $out = [];
        foreach ($rows->groupBy(fn (BrandClusterPage $r): int => (int) ($r->cluster?->service_id ?? 0)) as $serviceId => $group) {
            if ($serviceId === 0) {
                continue;
            }
            $demand = $group->sum(fn (BrandClusterPage $r): float => (float) ($volumes[(int) $r->cluster_id] ?? 0));
            $gap = $group->filter(fn (BrandClusterPage $r): bool => $r->state !== 'sufficient')->count() / max(1, $group->count());
            $positions = $group->pluck('position_28d')->filter(fn ($p): bool => $p !== null && (float) $p > 0)->map(fn ($p): float => (float) $p);
            $position = $positions->isEmpty() ? null : $positions->avg();
            $win = match (true) {
                $position === null => 0.9,
                $position <= 10 => 1.3,
                $position <= 20 => 1.15,
                default => 1.0,
            };
            $value = 1.0 + (isset($main[$serviceId]) ? 1.0 : 0.0) + (isset($paid[$serviceId]) ? 0.5 : 0.0);
            $out[(int) $serviceId] = ['score' => round($value * log10(2 + $demand) * (0.2 + $gap) * $win, 3), 'clusters' => $group->count(), 'gap' => round($gap, 2)];
        }
        uasort($out, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $out;
    }

    /** @param  array<int, array{clusters: int}>  $scores */
    public static function bigSite(array $scores): bool
    {
        return count($scores) >= self::BIG_SITE_SERVICES || array_sum(array_column($scores, 'clusters')) >= self::BIG_SITE_CLUSTERS;
    }

    /** A service whose map exists and has nothing left to write or strengthen. */
    public function finished(DigitalAsset $site, int $serviceId): bool
    {
        $topics = ContentTopic::query()->where('website_asset_id', $site->id)->where('service_id', $serviceId)->get(['id', 'coverage', 'status']);

        return $topics->isNotEmpty() && $topics->every(fn (ContentTopic $t): bool => $t->done());
    }

    /** Whether the service's map is there and younger than MAP_DAYS. */
    public function hasFreshMap(DigitalAsset $site, int $serviceId): bool
    {
        return ContentTopic::query()->where('website_asset_id', $site->id)->where('service_id', $serviceId)->where('created_at', '>=', now()->subDays(self::MAP_DAYS))->exists();
    }

    /**
     * Draws the service's map (AI) when it has none or it is older than MAP_DAYS. Runs inside the site's content job:
     * "queued" while Claude has not answered (the job comes back with the answer).
     *
     * @return array{status: string, topics?: int}
     */
    public function ensureMap(DigitalAsset $site, Brand $brand, int $serviceId): array
    {
        if ($this->hasFreshMap($site, $serviceId)) {
            return ['status' => 'ready'];
        }
        $rows = self::mainRows($site)->filter(fn (BrandClusterPage $r): bool => (int) ($r->cluster?->service_id ?? 0) === $serviceId)->values();
        if ($rows->isEmpty()) {
            return ['status' => 'no_clusters'];
        }
        $main = ContentPlanner::siteLanguages($site)[0];
        $pages = $this->pages($site, $main);
        $queries = $this->queries($rows->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all());
        $abroad = self::servesAbroad($site);
        $areas = SiteScope::areas($brand)->map(fn ($a): string => trim((string) ($a->district_name ?: $a->city_name ?: $a->name)))->filter()->unique()->take(12)->values()->all();
        $facts = array_intersect_key(app(BrandFacts::class)->forPrompt($brand), array_flip(['questions', 'objections', 'praise', 'regions', 'seasonality']));
        $service = MetaDesk::serviceNames([$serviceId])[$serviceId] ?? '';
        $existing = ContentTopic::query()->where('website_asset_id', $site->id)->where('service_id', $serviceId)->get();
        $result = $this->ai->run(new TopicMapAgent, [
            'brand' => $this->memory->contextFor($brand, [], [])['profile'],
            'service' => $service,
            'language' => $main,
            'month' => (int) now('Europe/Istanbul')->month,
            'serves_abroad' => $abroad,
            'branch_places' => $areas,
            'brand_facts' => $facts ?: null,
            'clusters' => $rows->map(fn (BrandClusterPage $r): array => array_filter([
                'cluster_id' => (int) $r->cluster_id, 'name' => (string) $r->cluster?->name, 'state' => $r->stateLabel(),
                'page_url' => $r->page?->url, 'queries' => array_slice($queries[(int) $r->cluster_id] ?? [], 0, 15),
                'ai_questions' => $r->cluster !== null ? array_slice(ClusterAudit::aiQuestions($r->cluster, $brand), 0, 8) : [],
            ], fn (mixed $v): bool => $v !== null && $v !== []))->values()->all(),
            'site_pages' => $pages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => (string) ($p->title ?: $p->h1)])->values()->all(),
            'kept_topics' => $existing->filter(fn (ContentTopic $t): bool => $t->status !== 'open')->map(fn (ContentTopic $t): array => ['type' => $t->type, 'question' => $t->question])->values()->all(),
            'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
        ] + Epicrisis::input($brand, 'content'), 300, 'topic-map-'.$site->id.'-'.$serviceId.'-'.now()->format('Y').'q'.now()->quarter);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status']];
        }

        return ['status' => 'ready', 'topics' => $this->store($site, $brand, $serviceId, (array) ($result['data']['topics'] ?? []), $rows, $pages, $queries, $abroad, $areas !== [], $existing)];
    }

    /**
     * Keeps the AI's topics that pass the rules (known type, real question, a usable title, a page of the site or none,
     * a cluster of the service or none, local only with branches, abroad only for sites serving abroad) and replaces
     * the open topics of an older map; topics already made into ideas or articles stay.
     *
     * @param  list<mixed>  $items
     * @param  Collection<int, BrandClusterPage>  $rows
     * @param  Collection<int, Page>  $pages
     * @param  array<int, list<array{text: string, demand: int}>>  $queries
     * @param  Collection<int, ContentTopic>  $existing
     */
    private function store(DigitalAsset $site, Brand $brand, int $serviceId, array $items, Collection $rows, Collection $pages, array $queries, bool $abroad, bool $local, Collection $existing): int
    {
        $byUrl = $pages->keyBy(fn (Page $p): string => SeoText::urlKey((string) $p->url));
        $rowsByCluster = $rows->keyBy(fn (BrandClusterPage $r): int => (int) $r->cluster_id);
        $kept = $existing->filter(fn (ContentTopic $t): bool => $t->status !== 'open')->values();
        $demandOf = [];
        foreach ($queries as $clusterId => $list) {
            foreach ($list as $q) {
                $demandOf[SeoText::fold($q['text'])] = ['demand' => $q['demand'], 'cluster_id' => (int) $clusterId];
            }
        }
        $terms = ForbiddenTerms::forBrand($brand);
        $fresh = [];
        $pillars = 0;
        foreach ($items as $item) {
            if (! is_array($item) || count($fresh) + $kept->count() >= self::MAX_TOPICS) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            $question = trim($terms->scrub((string) ($item['question'] ?? '')));
            $title = trim($terms->scrub((string) ($item['title'] ?? '')));
            if (! in_array($type, ContentTopic::TYPES, true) || mb_strlen($question) < 4 || mb_strlen($title) < 5 || ContentPlanner::styleProblem($title) !== null
                || ($type === 'abroad' && ! $abroad) || ($type === 'local' && ! $local) || ($type === 'pillar' && $pillars >= 1)) {
                continue;
            }
            $same = fn (string $a): bool => ContentMap::sameSubject($a, $title) || SeoText::fold($a) === SeoText::fold($question);
            if ($kept->contains(fn (ContentTopic $t): bool => $same($t->title) || $same($t->question)) || collect($fresh)->contains(fn (array $t): bool => $same($t['title']) || $same($t['question']))) {
                continue;
            }
            $pillars += $type === 'pillar' ? 1 : 0;
            $clusterId = (int) ($item['cluster_id'] ?? 0);
            $demand = $demandOf[SeoText::fold($question)] ?? null;
            $clusterId = $rowsByCluster->has($clusterId) ? $clusterId : ($demand['cluster_id'] ?? (int) $rows->first()->cluster_id);
            $row = $rowsByCluster->get($clusterId);
            $page = $byUrl->get(SeoText::urlKey((string) ($item['covered_by_url'] ?? ''))) ?? $this->map->owner($site, $question, $title, $pages);
            $coverage = $page === null ? 'missing' : ($row !== null && $row->state === 'sufficient' && (int) $row->page_id === (int) $page->id ? 'covered' : 'weak');
            if ($page !== null && ($item['coverage'] ?? null) === 'covered' && $coverage === 'weak' && $row?->state === 'sufficient') {
                $coverage = 'covered';
            }
            $season = $type === 'seasonal' && is_int($item['season_month'] ?? null) && $item['season_month'] >= 1 && $item['season_month'] <= 12 ? (int) $item['season_month'] : null;
            if ($type === 'seasonal' && $season === null) {
                continue;
            }
            $volume = (float) ($demand['demand'] ?? 0);
            $win = match (true) {
                $row?->position_28d === null => 0.9,
                (float) $row->position_28d <= 10 => 1.3,
                (float) $row->position_28d <= 20 => 1.15,
                default => 1.0,
            };
            $gap = ['missing' => 1.0, 'weak' => 0.6, 'covered' => 0.0][$coverage];
            $fresh[] = [
                'type' => $type, 'question' => mb_substr($question, 0, 300), 'title' => mb_substr($title, 0, 160), 'cluster_id' => $clusterId, 'coverage' => $coverage,
                'page_id' => $page?->id, 'season_month' => $season, 'priority' => round(log10(2 + $volume) * $win * (0.2 + $gap), 3),
                'evidence' => array_values(array_filter([
                    ['kind' => 'topic', 'value' => ContentTopic::TYPE_LABELS[$type].' · '.(string) ($row?->cluster?->name ?? ''), 'source' => 'konu haritası'],
                    $volume > 0 ? ['kind' => 'query', 'value' => '«'.$question.'» talep: '.number_format($volume, 0, ',', '.'), 'source' => 'sorgu kütüphanesi'] : null,
                    $page !== null ? ['kind' => 'page', 'value' => 'Bugün cevaplayan sayfa: '.SeoText::urlPath((string) $page->url), 'source' => 'site'] : null,
                ])),
            ];
        }
        if ($fresh === []) {
            return 0;
        }
        DB::transaction(function () use ($site, $brand, $serviceId, $fresh, $kept): void {
            ContentTopic::query()->where('website_asset_id', $site->id)->where('service_id', $serviceId)->where('status', 'open')->delete();
            // A kept topic counts as part of the new map.
            ContentTopic::query()->whereIn('id', $kept->pluck('id')->all() ?: [0])->update(['created_at' => now()]);
            foreach ($fresh as $topic) {
                ContentTopic::query()->create($topic + ['website_asset_id' => $site->id, 'brand_id' => $brand->id, 'service_id' => $serviceId, 'status' => 'open']);
            }
            $this->order($site, $serviceId);
        });

        return count($fresh);
    }

    /** Positions in writing order: type order first, then priority. */
    private function order(DigitalAsset $site, int $serviceId): void
    {
        ContentTopic::query()->where('website_asset_id', $site->id)->where('service_id', $serviceId)->get()
            ->sortBy(fn (ContentTopic $t): array => [array_search($t->type, ContentTopic::TYPES, true), -$t->priority, $t->id])->values()
            ->each(fn (ContentTopic $t, int $i) => $t->position !== $i + 1 ? $t->forceFill(['position' => $i + 1])->save() : null);
    }

    /**
     * Brings the maps of the site up to date by rules: a topic whose idea was sent is "sent", once its page is on the
     * site "live"; a dismissed idea frees its topic once (dropped after a second time); a page that now answers an
     * open topic places it (covered / weak).
     *
     * @return array{linked: int, sent: int, live: int, placed: int}
     */
    public function sync(DigitalAsset $site): array
    {
        $out = ['linked' => 0, 'sent' => 0, 'live' => 0, 'placed' => 0];
        $topics = ContentTopic::query()->where('website_asset_id', $site->id)->get();
        if ($topics->isEmpty()) {
            return $out;
        }
        $ideas = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', SiteSuggestionTypes::CONTENT)->where('action->site_id', (int) $site->id)
            ->whereNotNull('action->topic_id')->orderBy('id')->get(['id', 'status', 'page_id', 'action', 'brand_id', 'cluster_id', 'resolved_at', 'updated_at'])
            ->groupBy(fn (Suggestion $s): int => (int) data_get($s->action, 'topic_id'));
        $links = app(ClusterLinks::class);
        $pages = $this->pages($site, ContentPlanner::siteLanguages($site)[0]);
        foreach ($topics as $topic) {
            $idea = $ideas->get((int) $topic->id)?->last();
            $changes = [];
            if ($idea !== null && (int) $topic->suggestion_id !== (int) $idea->id) {
                $changes['suggestion_id'] = $idea->id;
                $out['linked']++;
            }
            if ($idea !== null) {
                $sent = isset($idea->action['article_write_id']) || $idea->status === Suggestion::APPLIED;
                $status = match (true) {
                    $sent && $links->livePage($idea, $site) !== null => 'live',
                    $sent => 'sent',
                    $idea->status === Suggestion::DISMISSED => ($ideas->get((int) $topic->id)->where('status', Suggestion::DISMISSED)->count() >= 2 ? 'dropped' : 'open'),
                    default => 'idea',
                };
                if ($status !== $topic->status) {
                    $changes['status'] = $status;
                    $out[$status] = ($out[$status] ?? 0) + 1;
                }
                if ($status === 'live' && ($live = $links->livePage($idea, $site)) !== null && (int) $topic->page_id !== (int) $live->id) {
                    $changes['page_id'] = $live->id;
                }
            } elseif ($topic->status === 'open' && $topic->coverage === 'missing' && ($page = $this->map->owner($site, $topic->question, $topic->title, $pages)) !== null) {
                $changes += ['page_id' => $page->id, 'coverage' => 'weak'];
                $out['placed']++;
            }
            if ($changes !== []) {
                $topic->forceFill($changes)->save();
            }
        }

        return $out;
    }

    /**
     * The week's topics of the active services: new articles from empty places in writing order (a seasonal topic only
     * 4–6 weeks before its month), updates from weak places (a page waiting for an update is skipped).
     *
     * @param  list<int>  $services
     * @return array{new: Collection<int, ContentTopic>, update: Collection<int, ContentTopic>}
     */
    public function next(DigitalAsset $site, array $services, int $new, int $updates): array
    {
        $open = ContentTopic::query()->where('website_asset_id', $site->id)->whereIn('service_id', $services ?: [0])->where('status', 'open')
            ->orderBy('position')->get();
        $now = now('Europe/Istanbul');
        $months = collect(range(self::SEASON_WEEKS[0], self::SEASON_WEEKS[1]))->map(fn (int $w): int => (int) $now->copy()->addWeeks($w)->month)->unique()->all();
        $due = fn (ContentTopic $t): bool => $t->type !== 'seasonal' || in_array((int) $t->season_month, $months, true);
        $busyPages = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', SiteSuggestionTypes::CONTENT)->whereNotNull('page_id')
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED])->pluck('page_id')->map(fn ($id): int => (int) $id)->flip()->all();
        // Seasonal topics that are due go first: their date does not wait.
        $missing = $open->where('coverage', 'missing')->filter($due)->sortBy(fn (ContentTopic $t): array => [$t->type === 'seasonal' ? 0 : 1, $t->position])->values();

        return [
            'new' => $missing->take($new)->values(),
            'update' => $open->where('coverage', 'weak')->filter(fn (ContentTopic $t): bool => $t->page_id !== null && ! isset($busyPages[(int) $t->page_id]))->take($updates)->values(),
        ];
    }

    /**
     * Küme tamamlama: per mapped service of the site, how much of its map is done, what is live, how much of it Google
     * shows in the top 10 and what waits.
     *
     * @return list<array{service_id: int, service: string, active: bool, total: int, done: int, live: int, top10: int, waiting: int, percent: int, topics: Collection<int, ContentTopic>}>
     */
    public function completion(DigitalAsset $site): array
    {
        $topics = ContentTopic::query()->with('page:id,url,title')->where('website_asset_id', $site->id)->orderBy('service_id')->orderBy('position')->get()->groupBy('service_id');
        if ($topics->isEmpty()) {
            return [];
        }
        $active = array_map('intval', (array) (ContentSiteState::query()->where('digital_asset_id', $site->id)->first()?->active_service_ids ?? []));
        $names = MetaDesk::serviceNames($topics->keys()->map(fn ($id): int => (int) $id)->all());
        $positions = $this->positions($topics->flatten()->pluck('page')->filter()->pluck('url')->map(fn ($u): string => (string) $u)->all());
        $out = [];
        foreach ($topics as $serviceId => $list) {
            $done = $list->filter(fn (ContentTopic $t): bool => $t->done())->count();
            $out[] = [
                'service_id' => (int) $serviceId, 'service' => $names[(int) $serviceId] ?? '', 'active' => in_array((int) $serviceId, $active, true),
                'total' => $list->count(), 'done' => $done, 'live' => $list->where('status', 'live')->count(),
                'top10' => $list->filter(fn (ContentTopic $t): bool => $t->page !== null && ($positions[SeoText::urlKey((string) $t->page->url)] ?? 99) <= 10)->count(),
                'waiting' => $list->filter(fn (ContentTopic $t): bool => ! $t->done())->count(),
                'percent' => (int) round(100 * $done / max(1, $list->count())), 'topics' => $list->values(),
            ];
        }
        usort($out, fn (array $a, array $b): int => [$b['active'], $b['percent']] <=> [$a['active'], $a['percent']]);

        return $out;
    }

    /**
     * Average Search Console position of the pages (28 days).
     *
     * @param  list<string>  $urls
     * @return array<string, float>
     */
    private function positions(array $urls): array
    {
        return array_filter(array_map(fn (array $s): ?float => $s['position'], PageSearchStats::forUrls($urls, 28)), fn (?float $p): bool => $p !== null);
    }

    /** A site that serves abroad: it has a language other than its main one, or its main language is not Turkish. */
    public static function servesAbroad(DigitalAsset $site): bool
    {
        $languages = ContentPlanner::siteLanguages($site);

        return count($languages) > 1 || ($languages[0] ?? 'tr') !== 'tr';
    }

    /** @return Collection<int, BrandClusterPage> the site's cluster rows in its main language, one per cluster */
    public static function mainRows(DigitalAsset $site): Collection
    {
        $main = ContentPlanner::siteLanguages($site)[0];

        return BrandClusterPage::query()->with(['cluster:id,name,service_id,ai_queries,main_query_id', 'page:id,url,title,path,language'])
            ->where('brand_id', $site->brand_id)->where('website_asset_id', $site->id)->where('excluded', false)
            ->where(fn ($q) => $q->whereNull('language')->orWhere('language', $main))->orderBy('id')->get()
            ->sortBy(fn (BrandClusterPage $r): array => [$r->language === $main ? 0 : 1, (int) $r->id])->unique('cluster_id')->values();
    }

    /**
     * The real searches of the clusters with their demand, most first.
     *
     * @param  list<int>  $clusterIds
     * @return array<int, list<array{text: string, demand: int}>>
     */
    private function queries(array $clusterIds): array
    {
        $out = [];
        DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds ?: [0])->where('q.hidden', false)
            ->orderBy('cq.cluster_id')->orderByRaw('(q.impressions + coalesce(q.volume, 0)) desc')->orderBy('q.id')
            ->get(['cq.cluster_id', 'q.text', 'q.impressions', 'q.volume'])
            ->each(function (object $r) use (&$out): void {
                if (count($out[(int) $r->cluster_id] ?? []) < 25) {
                    $out[(int) $r->cluster_id][] = ['text' => (string) $r->text, 'demand' => (int) $r->impressions + (int) ($r->volume ?? 0)];
                }
            });

        return $out;
    }

    /** @return Collection<int, Page> the site's indexable content pages in the language (no home, no archive) */
    private function pages(DigitalAsset $site, string $language): Collection
    {
        return Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'kurumsal'))->orderBy('id')->limit(1500)
            ->get(['id', 'url', 'path', 'title', 'h1', 'category', 'language', 'wp_post_type'])
            ->filter(fn (Page $p): bool => trim(SeoText::urlPath((string) $p->url), '/') !== '' && ContentPlanner::inLanguage($p, $language))->values();
    }
}
