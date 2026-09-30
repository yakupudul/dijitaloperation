<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryClusterAgent;
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
use Throwable;

/**
 * "AI ile kümele" for one service (clusters belong to SECTOR + SERVICE, shared by brands). The AI never sees raw
 * queries: the rule engine's topics (QueryRuleEngine: variants merged, facet words such as fiyat / nedir taken out)
 * go instead, one line per topic with its facets, variant count, metrics, example queries and the page Google shows
 * for it on the sector's sites (Search Console, last 90 days) — topics Google already answers with one page belong
 * together. Up to MAX_TOPICS topics (most impressions first) in ONE call; the result replaces the service's unlocked
 * clusters and every query of a topic joins its topic's cluster. Topic ids are head query ids, checked against the
 * input (each topic in one cluster); AI-added queries are stored as suggested (`is_suggested`, no metrics); user need
 * and exclusions are trimmed / capped. Locked clusters and their queries are never touched.
 */
final class QueryClusterer
{
    public const int MAX_TOPICS = 800;

    private const int GSC_DAYS = 90;

    private const int MAX_NEW_PER_CLUSTER = 5;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly QueryNormalizer $normalizer,
    ) {}

    public static function cacheKey(int $serviceId): string
    {
        return 'queries:cluster:'.$serviceId;
    }

    public static function markRunning(int $serviceId): void
    {
        Cache::put(self::cacheKey($serviceId), ['status' => 'running'], now()->addDay());
    }

    /** @return array{status: string, clusters: int, suggested: int} status: ready | no_sector | no_queries | no_provider | error */
    public function cluster(ServiceCatalogItem $service): array
    {
        $sector = ServiceCategory::query()->where('code', $service->sector)->first();
        if ($sector === null) {
            return ['status' => 'no_sector', 'clusters' => 0, 'suggested' => 0];
        }
        $lockedIds = Cluster::query()->where('sector_id', $sector->id)->where('service_id', $service->id)->where('locked', true)->pluck('id');
        $queries = Query::query()->where('service_id', $service->id)->where('hidden', false)->where('is_suggested', false)
            ->whereNotIn('id', ClusterQuery::query()->whereIn('cluster_id', $lockedIds)->select('query_id'))
            ->orderByDesc('impressions')->orderBy('id')
            ->get(['id', 'text', 'impressions', 'clicks', 'topic_key', 'facets']);
        if ($queries->isEmpty()) {
            return ['status' => 'no_queries', 'clusters' => 0, 'suggested' => 0];
        }
        $topics = $this->topics($queries, $this->googlePages((int) $sector->id, $queries->pluck('text')->all()));

        try {
            $route = $this->routes->resolve(QueryClusterAgent::OPERATION);
            if ($route->isEmpty()) {
                return ['status' => 'no_provider', 'clusters' => 0, 'suggested' => 0];
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $structured = (new QueryClusterAgent)->prompt(
                "DATA_JSON\n".json_encode([
                    'sector' => (string) $sector->name,
                    'service' => (string) ($service->primaryName?->raw_label ?? ''),
                    'topics' => array_values(array_map(fn (array $t): array => array_diff_key($t, ['members' => true]), $topics)),
                    'locked_clusters' => Cluster::query()->whereIn('id', $lockedIds)->pluck('name')->all(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 300,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Query clustering failed.', ['service_id' => $service->id, 'error' => $exception->getMessage()]);

            return ['status' => 'error', 'clusters' => 0, 'suggested' => 0];
        }

        return DB::transaction(fn (): array => $this->store($sector, $service, $queries, $topics, (array) ($structured['clusters'] ?? [])));
    }

    /**
     * One line per topic (head = most impressions), most impressions first, at most MAX_TOPICS.
     *
     * @param  Collection<int, Query>  $queries  most impressions first
     * @param  array<string, array<string, int>>  $pages  query text => page URL => impressions
     * @return array<int, array{id: int, topic: string, facets: list<string>, variants: int, impressions: int, clicks: int, examples: list<string>, google_url?: string, members: list<int>}>
     */
    private function topics(Collection $queries, array $pages): array
    {
        $topics = [];
        $pageTotals = [];
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
            foreach ($pages[(string) $query->text] ?? [] as $url => $impressions) {
                $pageTotals[$key][$url] = ($pageTotals[$key][$url] ?? 0) + $impressions;
            }
            unset($topic);
        }
        uasort($topics, fn (array $a, array $b): int => [$b['impressions'], $a['id']] <=> [$a['impressions'], $b['id']]);
        $out = [];
        foreach (array_slice($topics, 0, self::MAX_TOPICS, true) as $key => $topic) {
            $topic['facets'] = array_keys($topic['facets']);
            if (($pageTotals[$key] ?? []) !== []) {
                arsort($pageTotals[$key]);
                $topic['google_url'] = (string) array_key_first($pageTotals[$key]);
            }
            $out[$topic['id']] = $topic;
        }

        return $out;
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
     * @param  Collection<int, Query>  $queries
     * @param  array<int, array{members: list<int>}>  $topics  head query id => topic
     * @param  array<mixed>  $rows
     * @return array{status: string, clusters: int, suggested: int}
     */
    private function store(ServiceCategory $sector, ServiceCatalogItem $service, Collection $queries, array $topics, array $rows): array
    {
        // The previous AI proposal (unlocked clusters) is replaced; suggested queries left without a cluster go with it.
        Cluster::query()->where('sector_id', $sector->id)->where('service_id', $service->id)->where('locked', false)->delete();
        Query::query()->where('service_id', $service->id)->where('is_suggested', true)
            ->whereNotIn('id', ClusterQuery::query()->select('query_id'))->delete();

        $byText = $queries->pluck('id', 'text')->all();
        $usedTopics = [];
        $used = [];
        $clusters = 0;
        $suggested = 0;
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
                    $usedTopics[$id] = true;
                    $heads[] = $id;
                    foreach ($topics[$id]['members'] as $member) {
                        if (! isset($used[$member])) {
                            $ids[] = $member;
                        }
                    }
                }
            }
            if ($ids === [] || mb_strlen($name) < 2) {
                continue; // a cluster must hold at least one real query
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
            $now = now();
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

        return ['status' => 'ready', 'clusters' => $clusters, 'suggested' => $suggested];
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
