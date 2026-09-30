<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryClusterAgent;
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
use Throwable;

/**
 * "AI ile kümele" for one service (clusters belong to SECTOR + SERVICE, shared by brands): the service's visible
 * queries outside locked clusters (top by impressions) go to ONE AI call; the result replaces the service's unlocked
 * clusters. Query ids are checked against the input (each in one cluster); AI-added queries are stored as suggested
 * (`is_suggested`, no metrics); user need and exclusions (topics not to include) are trimmed / capped. Locked clusters
 * and their queries are never touched.
 */
final class QueryClusterer
{
    public const int MAX_QUERIES = 500;

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
            ->orderByDesc('impressions')->orderBy('id')->limit(self::MAX_QUERIES)
            ->get(['id', 'text', 'impressions', 'clicks']);
        if ($queries->isEmpty()) {
            return ['status' => 'no_queries', 'clusters' => 0, 'suggested' => 0];
        }

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
                    'queries' => $queries->map(fn (Query $q): array => ['id' => (int) $q->id, 'text' => (string) $q->text, 'impressions' => (int) $q->impressions, 'clicks' => (int) $q->clicks])->all(),
                    'locked_clusters' => Cluster::query()->whereIn('id', $lockedIds)->pluck('name')->all(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 300,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Query clustering failed.', ['service_id' => $service->id, 'error' => $exception->getMessage()]);

            return ['status' => 'error', 'clusters' => 0, 'suggested' => 0];
        }

        return DB::transaction(fn (): array => $this->store($sector, $service, $queries, (array) ($structured['clusters'] ?? [])));
    }

    /**
     * @param  Collection<int, Query>  $queries
     * @param  array<mixed>  $rows
     * @return array{status: string, clusters: int, suggested: int}
     */
    private function store(ServiceCategory $sector, ServiceCatalogItem $service, Collection $queries, array $rows): array
    {
        // The previous AI proposal (unlocked clusters) is replaced; suggested queries left without a cluster go with it.
        Cluster::query()->where('sector_id', $sector->id)->where('service_id', $service->id)->where('locked', false)->delete();
        Query::query()->where('service_id', $service->id)->where('is_suggested', true)
            ->whereNotIn('id', ClusterQuery::query()->select('query_id'))->delete();

        $byId = $queries->keyBy('id');
        $byText = $queries->pluck('id', 'text')->all();
        $used = [];
        $clusters = 0;
        $suggested = 0;
        foreach (array_slice($rows, 0, 100) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $ids = [];
            foreach ((array) ($row['query_ids'] ?? []) as $id) {
                if (is_int($id) && $byId->has($id) && ! isset($used[$id])) {
                    $ids[] = $id;
                }
            }
            if ($ids === [] || mb_strlen($name) < 2) {
                continue; // a cluster must hold at least one real query
            }
            $main = is_int($row['main_query_id'] ?? null) && in_array($row['main_query_id'], $ids, true)
                ? $row['main_query_id']
                : $queries->whereIn('id', $ids)->sortByDesc('impressions')->first()->id;
            $representatives = array_values(array_slice(array_diff(array_filter((array) ($row['representative_query_ids'] ?? []), 'is_int'), [$main]), 0, 3));
            $representatives = array_values(array_intersect($representatives, $ids));
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
