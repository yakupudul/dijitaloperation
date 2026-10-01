<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryClusterAgent;
use App\Ai\Agents\QueryClusterReviewAgent;
use App\Models\Brand;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\PendingQuery;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * "AI ile kümele" for one service (clusters belong to SECTOR + SERVICE, shared by brands). The AI never sees raw
 * queries: the rule engine's topics (QueryRuleEngine: variants merged, facet words such as fiyat / nedir taken out)
 * go instead, one line per topic with its facets, variant count, metrics, example queries and the page Google shows
 * for it on the sector's sites (Search Console, last 90 days).
 *
 * A run goes in steps, one AI call each (ClusterQueriesJob runs one step and queues the next), so no topic is cut off
 * and a failed call repeats only its own part:
 * - skeleton (full run): the service's unlocked clusters are replaced; the SKELETON_TOPICS most searched topics are
 *   grouped into clusters;
 * - place: the next PLACE_TOPICS unclustered topics (most searched first) join an existing cluster
 *   (`existing_cluster_id`, also a locked one — its definition never changes) or open a new one; until every topic is
 *   clustered or left out by the AI (not about this service);
 * - review: one call merges clusters one page would cover (never from a locked cluster) and clarifies unlocked ones.
 * A "place" run (new queries of a service that already has clusters) starts at place. Topic ids are head query ids,
 * checked against the input (each topic in one cluster); AI-added queries are stored as suggested (`is_suggested`, no
 * metrics). The run state (step, part, left-out queries) lives in the cache under cacheKey().
 */
final class QueryClusterer
{
    /** Topics of the skeleton call (most searched first). */
    public const int SKELETON_TOPICS = 400;

    /** Topics per placing call. */
    public const int PLACE_TOPICS = 300;

    private const int GSC_DAYS = 90;

    private const int MAX_NEW_PER_CLUSTER = 5;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly QueryNormalizer $normalizer,
    ) {}

    private static function skeletonTopics(): int
    {
        return max(1, (int) config('moxdop-query-rules.cluster.skeleton_topics', self::SKELETON_TOPICS));
    }

    private static function placeTopics(): int
    {
        return max(1, (int) config('moxdop-query-rules.cluster.place_topics', self::PLACE_TOPICS));
    }

    public static function cacheKey(int $serviceId): string
    {
        return 'queries:cluster:'.$serviceId;
    }

    /**
     * Starts a run: full (re-cluster: unlocked clusters are replaced) or place (only the unclustered queries go into the
     * existing clusters). The first step runs in ClusterQueriesJob.
     */
    public static function start(int $serviceId, string $mode = 'full'): void
    {
        Cache::put(self::cacheKey($serviceId), [
            'status' => 'running', 'mode' => $mode === 'place' ? 'place' : 'full', 'step' => $mode === 'place' ? 'place' : 'skeleton',
            'part' => 0, 'parts' => null, 'left_out' => [], 'clusters' => 0, 'suggested' => 0, 'started_at' => now()->toIso8601String(),
        ], now()->addDays(2));
    }

    /** Kept for callers that only mark a full run. */
    public static function markRunning(int $serviceId): void
    {
        self::start($serviceId);
    }

    /** @return array<string, mixed>|null */
    public static function state(int $serviceId): ?array
    {
        $state = Cache::get(self::cacheKey($serviceId));

        return is_array($state) ? $state : null;
    }

    /**
     * Operator wording of a run state: "kümeleniyor · parça 3 / 12", "hazır · 14 küme", "durduruldu", errors.
     *
     * @param  array<string, mixed>|null  $state
     * @return array{text: string, tone: string}|null tone: run | ok | error
     */
    public static function label(?array $state): ?array
    {
        $status = $state['status'] ?? null;
        if ($status === null) {
            return null;
        }
        if ($status === 'running') {
            $part = (int) ($state['part'] ?? 0) + 1;
            $parts = max($part, (int) ($state['parts'] ?? 0));
            $step = ['skeleton' => 'iskelet', 'place' => 'yerleştirme', 'review' => 'gözden geçirme'][$state['step'] ?? ''] ?? '';

            return ['text' => 'kümeleniyor · '.($step !== '' ? $step.' · ' : '').'parça '.$part.($parts > 0 ? ' / '.$parts : ''), 'tone' => 'run'];
        }
        if ($status === 'ready') {
            return ['text' => 'hazır'.(isset($state['clusters']) ? ' · '.(int) $state['clusters'].' yeni küme · '.(int) ($state['suggested'] ?? 0).' önerilen sorgu' : ''), 'tone' => 'ok'];
        }

        return ['text' => [
            'stopped' => 'durduruldu', 'no_queries' => 'sorgu yok', 'no_sector' => 'hizmetin sektörü yok',
            'no_provider' => 'AI bağlı değil', 'no_service' => 'hizmet yok', 'error' => 'hata · yeniden deneyin',
        ][$status] ?? (string) $status, 'tone' => $status === 'stopped' ? 'ok' : 'error'];
    }

    public static function stop(int $serviceId): void
    {
        $state = self::state($serviceId);
        if (($state['status'] ?? null) === 'running') {
            Cache::put(self::cacheKey($serviceId), ['status' => 'stopped'] + $state, now()->addDays(2));
        }
    }

    /**
     * Runs the next step of the service's run and stores the new state: status running (more steps follow), ready, or
     * no_sector / no_queries / no_provider. Throws when the AI call fails (the job repeats the same step).
     *
     * @return array<string, mixed>
     */
    public function step(ServiceCatalogItem $service): array
    {
        $state = self::state((int) $service->id) ?? [];
        if (($state['status'] ?? null) !== 'running') {
            return $state;
        }
        $sector = ServiceCategory::query()->where('code', $service->sector)->first();
        if ($sector === null) {
            return $this->save($service, ['status' => 'no_sector'] + $state);
        }
        if ($this->routes->resolve(QueryClusterAgent::OPERATION)->isEmpty()) {
            return $this->save($service, ['status' => 'no_provider'] + $state);
        }

        $step = (string) ($state['step'] ?? 'skeleton');
        if ($step === 'review') {
            $this->review($sector, $service);

            return $this->save($service, ['status' => 'ready', 'step' => 'done', 'part' => (int) ($state['part'] ?? 0) + 1] + $state);
        }

        if ($step === 'skeleton') {
            // The previous AI proposal (unlocked clusters) is replaced; suggested queries left without a cluster go with it.
            Cluster::query()->where('sector_id', $sector->id)->where('service_id', $service->id)->where('locked', false)->delete();
            Query::query()->where('service_id', $service->id)->where('is_suggested', true)
                ->whereNotIn('id', ClusterQuery::query()->select('query_id'))->delete();
            $state['left_out'] = [];
            $state['clusters'] = 0;
            $state['suggested'] = 0;
        }
        $leftOut = array_map('intval', (array) ($state['left_out'] ?? []));
        $queries = $this->unclustered($service, $leftOut);
        if ($queries->isEmpty()) {
            if ($step === 'skeleton' && ! Cluster::query()->where('service_id', $service->id)->exists()) {
                return $this->save($service, ['status' => 'no_queries'] + $state);
            }

            return $this->save($service, ['step' => 'review'] + $state);
        }
        $all = $this->topics($queries);
        $size = $step === 'skeleton' ? self::skeletonTopics() : self::placeTopics();
        $batch = array_slice($all, 0, $size, true);
        $rest = count($all) - count($batch);
        $state['parts'] = (int) ($state['part'] ?? 0) + 1 + (int) ceil($rest / self::placeTopics()) + 1;
        $this->attachGoogleUrls((int) $sector->id, $batch, $queries);

        $existing = $this->existingClusters($service);
        $structured = $this->ask(QueryClusterAgent::class, [
            'sector' => (string) $sector->name,
            'service' => (string) ($service->primaryName?->raw_label ?? ''),
            'topics' => array_values(array_map(fn (array $t): array => array_diff_key($t, ['members' => true]), $batch)),
            'existing_clusters' => $existing,
        ]);
        $result = DB::transaction(fn (): array => $this->apply($sector, $service, $queries, $batch, (array) ($structured['clusters'] ?? [])));

        // Topics of this part the AI left out (not about this service) are not asked again in this run.
        foreach (array_diff_key($batch, $result['placed']) as $topic) {
            array_push($leftOut, ...$topic['members']);
        }

        return $this->save($service, [
            'step' => $rest > 0 ? 'place' : 'review',
            'part' => (int) ($state['part'] ?? 0) + 1,
            'left_out' => array_values(array_unique($leftOut)),
            'clusters' => (int) ($state['clusters'] ?? 0) + $result['clusters'],
            'suggested' => (int) ($state['suggested'] ?? 0) + $result['suggested'],
        ] + $state);
    }

    /**
     * Stores the state unless the run was stopped meanwhile.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function save(ServiceCatalogItem $service, array $state): array
    {
        $current = self::state((int) $service->id);
        if (($current['status'] ?? null) === 'stopped') {
            return $current;
        }
        if (($state['status'] ?? null) !== 'running') {
            unset($state['left_out']);
        }
        Cache::put(self::cacheKey((int) $service->id), $state, now()->addDays(2));

        return $state;
    }

    /**
     * Visible, real queries of the service that are in none of its clusters (and not left out in this run), most
     * impressions first.
     *
     * @param  list<int>  $leftOut
     * @return Collection<int, Query>
     */
    private function unclustered(ServiceCatalogItem $service, array $leftOut): Collection
    {
        return Query::query()->where('service_id', $service->id)->where('hidden', false)->where('is_suggested', false)
            ->whereNotIn('id', ClusterQuery::query()->join('clusters', 'clusters.id', '=', 'cluster_queries.cluster_id')
                ->where('clusters.service_id', $service->id)->select('cluster_queries.query_id'))
            ->when($leftOut !== [], fn ($q) => $q->whereNotIn('id', $leftOut))
            ->orderByDesc('impressions')->orderBy('id')
            ->get(['id', 'text', 'impressions', 'clicks', 'topic_key', 'facets']);
    }

    /**
     * The service's clusters as the AI sees them: id, name, intent, page type, need, up to 5 most searched queries and
     * whether the operator fixed it.
     *
     * @return list<array<string, mixed>>
     */
    private function existingClusters(ServiceCatalogItem $service): array
    {
        $clusters = Cluster::query()->where('service_id', $service->id)->orderBy('id')->get();
        $examples = [];
        if ($clusters->isNotEmpty()) {
            ClusterQuery::query()->join('queries', 'queries.id', '=', 'cluster_queries.query_id')
                ->whereIn('cluster_queries.cluster_id', $clusters->pluck('id'))
                ->orderByDesc('queries.impressions')->orderBy('queries.id')
                ->get(['cluster_queries.cluster_id', 'queries.text'])
                ->each(function ($row) use (&$examples): void {
                    $id = (int) $row->cluster_id;
                    if (count($examples[$id] ?? []) < 5) {
                        $examples[$id][] = (string) $row->text;
                    }
                });
        }

        return $clusters->map(fn (Cluster $cluster): array => [
            'id' => (int) $cluster->id, 'name' => (string) $cluster->name, 'intent' => (string) $cluster->intent,
            'page_type' => (string) $cluster->page_type, 'user_need' => (string) $cluster->user_need,
            'examples' => $examples[(int) $cluster->id] ?? [], 'locked' => (bool) $cluster->locked,
        ])->values()->all();
    }

    /**
     * One structured call of a cluster agent; throws when no provider answers (the job repeats the step).
     *
     * @param  class-string  $agent
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ask(string $agent, array $data): array
    {
        $route = $this->routes->resolve($agent::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('No AI provider for '.$agent::OPERATION.'.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));

        return (array) (new $agent)->prompt(
            "DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            provider: $route->providerModels,
            timeout: 300,
        )->toArray();
    }

    /**
     * Last step: clusters one page would cover are merged (never from a locked cluster), unclear unlocked clusters are
     * rewritten. A failed review leaves the clusters as they are.
     */
    private function review(ServiceCategory $sector, ServiceCatalogItem $service): void
    {
        $clusters = Cluster::query()->where('service_id', $service->id)->withCount('clusterQueries')->orderBy('id')->get()->keyBy('id');
        if ($clusters->where('locked', false)->count() < 2 || $this->routes->resolve(QueryClusterReviewAgent::OPERATION)->isEmpty()) {
            return;
        }
        $examples = collect($this->existingClusters($service))->keyBy('id');
        $impressions = ClusterQuery::query()->join('queries', 'queries.id', '=', 'cluster_queries.query_id')
            ->whereIn('cluster_queries.cluster_id', $clusters->keys())->groupBy('cluster_queries.cluster_id')
            ->selectRaw('cluster_queries.cluster_id, sum(queries.impressions) as total')->pluck('total', 'cluster_queries.cluster_id');
        try {
            $structured = $this->ask(QueryClusterReviewAgent::class, [
                'sector' => (string) $sector->name,
                'service' => (string) ($service->primaryName?->raw_label ?? ''),
                'clusters' => $clusters->map(fn (Cluster $cluster): array => [
                    'id' => (int) $cluster->id, 'name' => (string) $cluster->name, 'intent' => (string) $cluster->intent,
                    'page_type' => (string) $cluster->page_type, 'user_need' => (string) $cluster->user_need,
                    'subtopics' => (array) $cluster->subtopics, 'queries' => (int) $cluster->cluster_queries_count,
                    'impressions' => (int) ($impressions[$cluster->id] ?? 0),
                    'top_queries' => $examples[(int) $cluster->id]['examples'] ?? [], 'locked' => (bool) $cluster->locked,
                ])->values()->all(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Query cluster review failed.', ['service_id' => $service->id, 'error' => $exception->getMessage()]);

            return;
        }

        DB::transaction(function () use ($structured, $clusters): void {
            $gone = [];
            foreach (array_slice((array) ($structured['merges'] ?? []), 0, 100) as $merge) {
                $into = is_array($merge) && is_int($merge['into_id'] ?? null) ? $clusters->get($merge['into_id']) : null;
                if ($into === null || isset($gone[$into->id])) {
                    continue;
                }
                foreach ((array) ($merge['from_ids'] ?? []) as $fromId) {
                    $from = is_int($fromId) ? $clusters->get($fromId) : null;
                    if ($from === null || $from->locked || $from->id === $into->id || isset($gone[$from->id])) {
                        continue;
                    }
                    $have = ClusterQuery::query()->where('cluster_id', $into->id)->pluck('query_id')->all();
                    ClusterQuery::query()->where('cluster_id', $from->id)->whereNotIn('query_id', $have ?: [0])->update(['cluster_id' => $into->id]);
                    $from->delete();
                    $gone[$from->id] = true;
                }
            }
            foreach (array_slice((array) ($structured['updates'] ?? []), 0, 200) as $update) {
                $cluster = is_array($update) && is_int($update['id'] ?? null) ? $clusters->get($update['id']) : null;
                if ($cluster === null || $cluster->locked || isset($gone[$cluster->id]) || mb_strlen(trim((string) ($update['name'] ?? ''))) < 2) {
                    continue;
                }
                $cluster->forceFill([
                    'name' => mb_substr(trim((string) $update['name']), 0, 200),
                    'intent' => in_array($update['intent'] ?? null, Cluster::INTENTS, true) ? $update['intent'] : $cluster->intent,
                    'page_type' => in_array($update['page_type'] ?? null, Cluster::PAGE_TYPES, true) ? $update['page_type'] : $cluster->page_type,
                    'user_need' => ($need = mb_substr(trim((string) ($update['user_need'] ?? '')), 0, 500)) !== '' ? $need : $cluster->user_need,
                    'subtopics' => ClusterEditor::lines(array_filter((array) ($update['subtopics'] ?? []), 'is_string')) ?: $cluster->subtopics,
                    'exclusions' => ClusterEditor::lines(array_filter((array) ($update['exclusions'] ?? []), 'is_string')),
                ])->save();
            }
        });
    }

    /**
     * One line per topic (head = most impressions), most impressions first.
     *
     * @param  Collection<int, Query>  $queries  most impressions first
     * @return array<int, array{id: int, topic: string, facets: list<string>, variants: int, impressions: int, clicks: int, examples: list<string>, google_url?: string, members: list<int>}>
     */
    private function topics(Collection $queries): array
    {
        $topics = [];
        foreach ($queries as $query) {
            $key = (string) ($query->topic_key ?: $query->text);
            if (! isset($topics[$key])) {
                $topics[$key] = ['id' => (int) $query->id, 'topic' => (string) $query->text, 'facets' => [], 'variants' => 0,
                    'impressions' => 0, 'clicks' => 0, 'examples' => [], 'members' => []];
            }
            $topic = &$topics[$key];
            $topic['variants']++;
            $topic['impressions'] += (int) $query->impressions;
            $topic['clicks'] += (int) $query->clicks;
            $topic['members'][] = (int) $query->id;
            foreach (array_filter(explode(',', (string) $query->facets)) as $facet) {
                $topic['facets'][$facet] = true;
            }
            if ((int) $query->id !== $topic['id'] && count($topic['examples']) < 3) {
                $topic['examples'][] = (string) $query->text;
            }
            unset($topic);
        }
        uasort($topics, fn (array $a, array $b): int => [$b['impressions'], $a['id']] <=> [$a['impressions'], $b['id']]);
        $out = [];
        foreach ($topics as $topic) {
            $topic['facets'] = array_keys($topic['facets']);
            $out[$topic['id']] = $topic;
        }

        return $out;
    }

    /**
     * The page Google shows most for each topic of the part on the sector's sites.
     *
     * @param  array<int, array<string, mixed>>  $batch
     * @param  Collection<int, Query>  $queries
     */
    private function attachGoogleUrls(int $sectorId, array &$batch, Collection $queries): void
    {
        $texts = $queries->keyBy('id');
        $wanted = [];
        foreach ($batch as $topic) {
            foreach ($topic['members'] as $member) {
                $wanted[] = (string) $texts[$member]->text;
            }
        }
        $pages = $this->googlePages($sectorId, $wanted);
        if ($pages === []) {
            return;
        }
        foreach ($batch as $id => $topic) {
            $totals = [];
            foreach ($topic['members'] as $member) {
                foreach ($pages[(string) $texts[$member]->text] ?? [] as $url => $impressions) {
                    $totals[$url] = ($totals[$url] ?? 0) + $impressions;
                }
            }
            if ($totals !== []) {
                arsort($totals);
                $batch[$id]['google_url'] = (string) array_key_first($totals);
            }
        }
    }

    /**
     * The pages Google shows for these query texts on the sector's websites (operational brands, Search Console, last
     * GSC_DAYS days): text => page => impressions.
     *
     * @param  list<string>  $texts
     * @return array<string, array<string, int>>
     */
    private function googlePages(int $sectorId, array $texts): array
    {
        if (! Schema::hasTable('gsc_query_page_daily')) {
            return [];
        }
        $wanted = array_fill_keys($texts, true);
        // Search Console accounts bound to the sector's websites (facts are keyed by the account).
        $resourceIds = DB::table('core_asset_bindings as bd')->join('digital_assets as a', 'a.id', '=', 'bd.digital_asset_id')
            ->join('brands as b', 'b.id', '=', 'a.brand_id')
            ->whereIn('b.id', Brand::query()->operational()->select('brands.id'))
            ->where('bd.status', 'active')->where('a.type', 'website')->whereNull('a.deleted_at')
            ->whereRaw('COALESCE(a.sector_id, b.sector_id) = ?', [$sectorId])->distinct()->pluck('bd.external_resource_id')->all();
        $pages = [];
        foreach ($resourceIds as $resourceId) {
            DB::table('gsc_query_page_daily')->where('external_resource_id', $resourceId)
                ->where('reporting_date', '>=', now()->subDays(self::GSC_DAYS)->toDateString())
                ->groupBy('query', 'page')->selectRaw('query, page, sum(impressions) as impressions')
                ->orderBy('query')->orderBy('page')
                ->each(function ($row) use (&$pages, $wanted): void {
                    $text = $this->normalizer->normalize((string) $row->query);
                    if (isset($wanted[$text]) && (int) $row->impressions > 0) {
                        $pages[$text][(string) $row->page] = ($pages[$text][(string) $row->page] ?? 0) + (int) $row->impressions;
                    }
                }, 5000);
        }

        return $pages;
    }

    /**
     * Stores one part's answer: rows with an `existing_cluster_id` of this service add their topics to that cluster
     * (its definition does not change); other rows become new clusters.
     *
     * @param  Collection<int, Query>  $queries
     * @param  array<int, array{members: list<int>}>  $topics  head query id => topic (this part)
     * @param  array<mixed>  $rows
     * @return array{clusters: int, suggested: int, placed: array<int, bool>}
     */
    private function apply(ServiceCategory $sector, ServiceCatalogItem $service, Collection $queries, array $topics, array $rows): array
    {
        $byText = $queries->pluck('id', 'text')->all();
        $existing = Cluster::query()->where('service_id', $service->id)->pluck('id')->flip();
        $usedTopics = [];
        $used = [];
        $clusters = 0;
        $suggested = 0;
        $now = now();
        foreach (array_slice($rows, 0, 100) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            // Topic ids come back; every query of a topic joins the cluster.
            $heads = [];
            $ids = [];
            foreach ((array) ($row['query_ids'] ?? []) as $id) {
                if (is_int($id) && isset($topics[$id]) && ! isset($usedTopics[$id])) {
                    $heads[] = $id;
                    foreach ($topics[$id]['members'] as $member) {
                        if (! isset($used[$member])) {
                            $ids[] = $member;
                        }
                    }
                }
            }
            $target = is_int($row['existing_cluster_id'] ?? null) && $existing->has($row['existing_cluster_id']) ? (int) $row['existing_cluster_id'] : null;
            if ($ids === [] || ($target === null && mb_strlen($name) < 2)) {
                continue; // a cluster must hold at least one real query
            }
            foreach ($heads as $head) {
                $usedTopics[$head] = true;
            }
            if ($target !== null) {
                ClusterQuery::query()->insert(array_map(fn (int $id): array => ['cluster_id' => $target, 'query_id' => $id, 'is_suggested' => false, 'created_at' => $now, 'updated_at' => $now], $ids));
                foreach ($ids as $id) {
                    $used[$id] = true;
                }

                continue;
            }
            $main = is_int($row['main_query_id'] ?? null) && in_array($row['main_query_id'], $heads, true)
                ? $row['main_query_id']
                : $queries->whereIn('id', $heads)->sortByDesc('impressions')->first()->id;
            $representatives = array_values(array_intersect(array_filter((array) ($row['representative_query_ids'] ?? []), 'is_int'), $heads));
            $representatives = array_values(array_slice(array_diff($representatives, [$main]), 0, 3));
            $cluster = Cluster::query()->create([
                'sector_id' => $sector->id, 'service_id' => $service->id, 'name' => mb_substr($name, 0, 200),
                'intent' => in_array($row['intent'] ?? null, Cluster::INTENTS, true) ? $row['intent'] : 'commercial',
                'user_need' => ($need = mb_substr(trim((string) ($row['user_need'] ?? '')), 0, 500)) !== '' ? $need : null,
                'main_query_id' => $main, 'representative_query_ids' => $representatives,
                'page_type' => in_array($row['page_type'] ?? null, Cluster::PAGE_TYPES, true) ? $row['page_type'] : 'other',
                'subtopics' => ClusterEditor::lines(array_filter((array) ($row['subtopics'] ?? []), 'is_string')),
                'exclusions' => ClusterEditor::lines(array_filter((array) ($row['exclusions'] ?? []), 'is_string')),
                'reasoning' => mb_substr(trim((string) ($row['reasoning'] ?? '')), 0, 1000),
            ]);
            $existing->put($cluster->id, true);
            $members = [];
            foreach ($ids as $id) {
                $used[$id] = true;
                $members[] = ['cluster_id' => $cluster->id, 'query_id' => $id, 'is_suggested' => false, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_slice((array) ($row['new_queries'] ?? []), 0, self::MAX_NEW_PER_CLUSTER) as $text) {
                $queryId = $this->suggestedQuery((string) $text, $sector, $service, $byText, $used);
                if ($queryId !== null) {
                    $used[$queryId] = true;
                    $members[] = ['cluster_id' => $cluster->id, 'query_id' => $queryId, 'is_suggested' => true, 'created_at' => $now, 'updated_at' => $now];
                    $suggested++;
                }
            }
            ClusterQuery::query()->insert($members);
            $clusters++;
        }

        return ['clusters' => $clusters, 'suggested' => $suggested, 'placed' => $usedTopics];
    }

    /**
     * A query the model added: normalized like any query; an existing text is never duplicated (only a free query of the
     * same service is reused); otherwise stored as suggested, without metrics.
     *
     * @param  array<string, int>  $byText
     * @param  array<int, bool>  $used
     */
    private function suggestedQuery(string $text, ServiceCategory $sector, ServiceCatalogItem $service, array $byText, array $used): ?int
    {
        $normalized = $this->normalizer->normalize($text);
        if (mb_strlen($normalized) < 3) {
            return null;
        }
        if (isset($byText[$normalized])) {
            return isset($used[$byText[$normalized]]) ? null : $byText[$normalized];
        }
        $hash = QueryNormalizer::hash($normalized);
        if (Query::query()->where('text_hash', $hash)->exists() || $this->normalizer->matchingTerm($normalized) !== null
            || PendingQuery::query()->where('text_hash', $hash)->where('status', '!=', PendingQuery::PENDING)->exists()) {
            return null;
        }

        return (int) Query::query()->create([
            'text' => $normalized, 'text_hash' => $hash, 'sector_id' => $sector->id, 'service_id' => $service->id,
            'assignment' => 'ai', 'is_suggested' => true,
        ])->id;
    }
}
