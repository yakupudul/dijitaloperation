<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryClusterAgent;
use App\Ai\Agents\QueryClusterReviewAgent;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\DigitalAsset;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\SiteFlow;
use DomainException;
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
 *   (`existing_cluster_id`, also a locked one — its definition never changes) or open a new one; topics the AI skips
 *   (another service of the sector / not relevant) are counted with their reason, topics its answer does not mention
 *   are asked once more in a later part and then counted as unprocessed;
 * - review: one call merges clusters one page would cover (never from a locked cluster) and clarifies unlocked ones.
 * A "place" run (new queries of a service that already has clusters) starts at place. Topic ids are head query ids,
 * checked against the input (each topic in one cluster); the model never adds queries (only collected searches are
 * clustered) and catch-all clusters are refused. A finished run approves its sound clusters (autoApprove). No caps on
 * the answer: every valid row is stored. The run state (step, part, left-out queries) lives in the cache under cacheKey().
 *
 * Delegated to Claude (MCP queue, operator decision 2026-10-03): a step asks once and waits (`waiting` = claude) with
 * its pack stored in the state (`pending`), so the answer is found again by the same input although triage adds
 * queries meanwhile; the job is dispatched again when Claude answers and the step goes on with that answer.
 */
final class QueryClusterer
{
    /** Topics of the skeleton call (most searched first). */
    public const int SKELETON_TOPICS = 400;

    /** Topics per placing call. */
    public const int PLACE_TOPICS = 300;

    private const int GSC_DAYS = 90;

    /** Names of catch-all clusters (one page cannot answer them): such a cluster is refused, its topics asked again. */
    private const string CATCH_ALL = '/^(diger|digerleri|cesitli|genel|genel sorular|diger sorular|karisik|other|misc|miscellaneous|general)\b/u';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly QueryNormalizer $normalizer,
        private readonly AiTaskQueue $tasks,
    ) {}

    /** A waiting run keeps its state longer (Claude answers in working hours; a weekend lies between). */
    private const int WAITING_DAYS = 5;

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
            'part' => 0, 'parts' => null, 'left_out' => [], 'missed' => [], 'clusters' => 0, 'suggested' => 0, 'started_at' => now()->toIso8601String(),
            'skipped' => ['other_service' => 0, 'not_relevant' => 0, 'unprocessed' => 0, 'services' => []],
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

            return ['text' => (($state['waiting'] ?? null) === 'claude' ? 'Claude bekleniyor' : 'kümeleniyor').' · '.($step !== '' ? $step.' · ' : '').'parça '.$part.($parts > 0 ? ' / '.$parts : ''), 'tone' => 'run'];
        }
        if ($status === 'ready') {
            return ['text' => 'hazır'.(isset($state['clusters']) ? ' · '.(int) $state['clusters'].' yeni küme' : '')
                .(isset($state['approved']) ? ' · '.(int) $state['approved'].' otomatik onaylandı' : '')
                .(! empty($state['by_rule']) ? ' · '.(int) $state['by_rule'].' sorgu kuralla yerleşti (AI\'sız)' : ''), 'tone' => 'ok'];
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
        if (! $this->tasks->delegated(QueryClusterAgent::OPERATION) && $this->routes->resolve(QueryClusterAgent::OPERATION)->isEmpty()) {
            return $this->save($service, ['status' => 'no_provider'] + $state);
        }

        $step = (string) ($state['step'] ?? 'skeleton');
        $pending = is_array($state['pending'] ?? null) ? $state['pending'] : null;
        unset($state['pending'], $state['waiting']);
        if ($step === 'review') {
            $waiting = $this->review($sector, $service, $pending);
            if ($waiting !== null) {
                return $this->save($service, ['waiting' => 'claude', 'pending' => $waiting] + $state);
            }
            $approved = $this->autoApprove($service);

            return $this->save($service, ['status' => 'ready', 'step' => 'done', 'approved' => $approved, 'part' => (int) ($state['part'] ?? 0) + 1] + $state);
        }

        if ($step === 'skeleton' && $pending === null) {
            // The previous AI proposal (unlocked, unapproved clusters) is replaced; approved clusters are in use by brands and
            // stay as existing clusters. Suggested queries left without a cluster go with it.
            Cluster::query()->where('sector_id', $sector->id)->where('service_id', $service->id)->where('locked', false)->where('approved', false)->delete();
            Query::query()->where('service_id', $service->id)->where('is_suggested', true)
                ->whereNotIn('id', ClusterQuery::query()->select('query_id'))->delete();
            // A full run looks at every query of the service again.
            Query::query()->where('service_id', $service->id)->whereNotNull('cluster_checked_at')->update(['cluster_checked_at' => null]);
            $state['left_out'] = [];
            $state['missed'] = [];
            $state['skipped'] = ['other_service' => 0, 'not_relevant' => 0, 'unprocessed' => 0, 'services' => []];
            $state['clusters'] = 0;
            $state['suggested'] = 0;
        }
        $leftOut = array_map('intval', (array) ($state['left_out'] ?? []));
        $queries = $this->unclustered($service, $leftOut, (string) ($state['mode'] ?? 'full') === 'place');
        if ($pending !== null) {
            // The part Claude was asked about, as it was asked (queries triage added meanwhile wait for a later part).
            // A topic whose head query left the service meanwhile (hidden, moved) is dropped; gone members never join.
            $present = $queries->pluck('id')->map(fn ($id): int => (int) $id)->flip();
            $batch = [];
            foreach ((array) $pending['batch'] as $head => $topic) {
                if (isset($present[(int) $head])) {
                    $topic['members'] = array_values(array_filter((array) $topic['members'], fn ($member): bool => isset($present[(int) $member])));
                    $batch[(int) $head] = $topic;
                }
            }
            $data = (array) $pending['data'];
            $rest = (int) $pending['rest'];
        } else {
            // Öğrenilmiş kural: a new query of a topic the clusters already hold (same rule-engine topic) joins that cluster
            // without AI; the AI only sees topics no cluster has yet.
            [$queries, $byRule] = $this->placeByTopic($service, $queries);
            $state['by_rule'] = (int) ($state['by_rule'] ?? 0) + $byRule;
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
            $data = [
                'sector' => (string) $sector->name,
                'service' => (string) ($service->primaryName?->raw_label ?? ''),
                'topics' => array_values(array_map(fn (array $t): array => array_diff_key($t, ['members' => true]), $batch)),
                'existing_clusters' => $this->existingClusters($service),
                'other_services' => $this->otherServices($service),
            ];
        }
        try {
            $structured = $this->ask(QueryClusterAgent::class, $data);
        } catch (DomainException $exception) {
            return $this->save($service, ['status' => 'error', 'error' => $exception->getMessage()] + $state);
        }
        if ($structured === null) {
            return $this->save($service, ['waiting' => 'claude', 'pending' => ['batch' => $batch, 'data' => $data, 'rest' => $rest]] + $state);
        }
        $result = DB::transaction(fn (): array => $this->apply($sector, $service, $queries, $batch, (array) ($structured['clusters'] ?? [])));

        // Topics the AI skipped (another service / not relevant) are not asked again in this run; topics its answer did
        // not mention are asked once more in a later part, then counted as unprocessed.
        $skipped = (array) ($state['skipped'] ?? ['other_service' => 0, 'not_relevant' => 0, 'unprocessed' => 0, 'services' => []]);
        $missed = (array) ($state['missed'] ?? []);
        $skips = [];
        foreach ((array) ($structured['skipped'] ?? []) as $skip) {
            if (is_array($skip) && is_int($skip['id'] ?? null)) {
                $skips[$skip['id']] = $skip;
            }
        }
        $retry = false;
        $checked = array_merge(...array_values(array_map(fn (array $topic): array => $topic['members'], array_intersect_key($batch, $result['placed']))) ?: [[]]);
        foreach (array_diff_key($batch, $result['placed']) as $head => $topic) {
            $skip = $skips[$head] ?? null;
            $count = count($topic['members']);
            if ($skip !== null) {
                $reason = ($skip['reason'] ?? null) === 'other_service' ? 'other_service' : 'not_relevant';
                $skipped[$reason] = (int) ($skipped[$reason] ?? 0) + $count;
                $other = trim((string) ($skip['service'] ?? ''));
                if ($reason === 'other_service' && $other !== '') {
                    $skipped['services'][$other] = (int) ($skipped['services'][$other] ?? 0) + $count;
                }
            } elseif ((int) ($missed[$head] ?? 0) < 1) {
                $missed[$head] = 1;
                $retry = true;

                continue;
            } else {
                $skipped['unprocessed'] = (int) ($skipped['unprocessed'] ?? 0) + $count;
            }
            unset($missed[$head]);
            array_push($leftOut, ...$topic['members']);
            $checked = [...$checked, ...$topic['members']];
        }
        // Placed and finally skipped queries are never sent to AI for clustering again (until a full run).
        foreach (array_chunk(array_values(array_unique(array_map('intval', $checked))), 1000) as $chunk) {
            Query::query()->whereIn('id', $chunk)->update(['cluster_checked_at' => now()]);
        }

        return $this->save($service, [
            'step' => $rest > 0 || $retry ? 'place' : 'review',
            'missed' => $missed,
            'skipped' => $skipped,
            'part' => (int) ($state['part'] ?? 0) + 1,
            'left_out' => array_values(array_unique($leftOut)),
            'clusters' => (int) ($state['clusters'] ?? 0) + $result['clusters'],
            'suggested' => (int) ($state['suggested'] ?? 0) + $result['suggested'],
        ] + $state);
    }

    /**
     * Unclustered queries whose topic key one cluster of the service already holds go into that cluster (a key held by
     * several clusters is left to the AI).
     *
     * @param  Collection<int, Query>  $queries
     * @return array{0: Collection<int, Query>, 1: int} the queries left for the AI, placed count
     */
    private function placeByTopic(ServiceCatalogItem $service, Collection $queries): array
    {
        $keys = $queries->pluck('topic_key')->filter()->unique()->values()->all();
        if ($keys === []) {
            return [$queries, 0];
        }
        $owners = ClusterQuery::query()->join('clusters', 'clusters.id', '=', 'cluster_queries.cluster_id')->join('queries', 'queries.id', '=', 'cluster_queries.query_id')
            ->where('clusters.service_id', $service->id)->whereIn('queries.topic_key', $keys)->distinct()->get(['queries.topic_key', 'cluster_queries.cluster_id'])
            ->groupBy('topic_key')->filter(fn (Collection $rows): bool => $rows->count() === 1)->map(fn (Collection $rows): int => (int) $rows->first()->cluster_id);
        if ($owners->isEmpty()) {
            return [$queries, 0];
        }
        $placed = $queries->filter(fn (Query $q): bool => $q->topic_key !== null && $owners->has($q->topic_key));
        $now = now();
        ClusterQuery::query()->insert($placed->map(fn (Query $q): array => ['cluster_id' => $owners[$q->topic_key], 'query_id' => (int) $q->id, 'is_suggested' => false,
            'created_at' => $now, 'updated_at' => $now])->values()->all());
        Query::query()->whereIn('id', $placed->pluck('id'))->update(['cluster_checked_at' => $now]);

        $placedIds = $placed->pluck('id')->map(fn ($id): int => (int) $id)->flip();

        return [$queries->reject(fn (Query $q): bool => isset($placedIds[(int) $q->id]))->values(), $placed->count()];
    }

    /**
     * Names of the sector's other active services: topics about them are skipped, not clustered here.
     *
     * @return list<string>
     */
    private function otherServices(ServiceCatalogItem $service): array
    {
        return ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')->where('sector', $service->sector)
            ->whereKeyNot($service->id)->limit(200)->get()
            ->map(fn (ServiceCatalogItem $item): string => (string) ($item->primaryName?->raw_label ?? ''))
            ->filter()->values()->all();
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
            unset($state['left_out'], $state['missed'], $state['pending'], $state['waiting']);
        }
        Cache::put(self::cacheKey((int) $service->id), $state, now()->addDays(isset($state['waiting']) ? self::WAITING_DAYS : 2));

        return $state;
    }

    /**
     * Visible, real queries of the service that are in none of its clusters (and not left out in this run), most
     * impressions first.
     *
     * @param  list<int>  $leftOut
     * @return Collection<int, Query>
     */
    private function unclustered(ServiceCatalogItem $service, array $leftOut, bool $newOnly = false): Collection
    {
        return Query::query()->where('service_id', $service->id)->where('hidden', false)->where('is_suggested', false)
            ->when($newOnly, fn ($q) => $q->whereNull('cluster_checked_at'))
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
     * One structured call of a cluster agent; throws when no provider answers (the job repeats the step). Delegated to
     * Claude inside the job's run: null while the answer is awaited, DomainException when Claude could not do it.
     *
     * @param  class-string<QueryClusterAgent|QueryClusterReviewAgent>  $agent
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function ask(string $agent, array $data): ?array
    {
        if ($this->tasks->delegated($agent::OPERATION)) {
            $answer = $this->tasks->answer(new $agent, $data);
            if ($answer !== null) {
                return match ($answer['status']) {
                    'ready' => $answer['data'],
                    'queued' => null,
                    default => throw new DomainException('Claude bu kümeleme adımını yapamadı.'),
                };
            }
            if ($this->tasks->blocksInline($agent::OPERATION)) {
                throw new DomainException('Kümeleme Claude\'a devredildi; yalnız arka plan işinden çalışır.');
            }
        }
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
     * rewritten. A failed review leaves the clusters as they are. Waiting for Claude: the pack asked (stored by the caller
     * and given back as $pending on the re-run, so the same question finds its answer).
     *
     * @param  array<string, mixed>|null  $pending
     * @return array<string, mixed>|null the pending pack while Claude's answer is awaited
     */
    private function review(ServiceCategory $sector, ServiceCatalogItem $service, ?array $pending = null): ?array
    {
        $clusters = Cluster::query()->where('service_id', $service->id)->withCount('clusterQueries')->orderBy('id')->get()->keyBy('id');
        if ($clusters->where('locked', false)->count() < 2
            || (! $this->tasks->delegated(QueryClusterReviewAgent::OPERATION) && $this->routes->resolve(QueryClusterReviewAgent::OPERATION)->isEmpty())) {
            return null;
        }
        try {
            $data = is_array($pending['data'] ?? null) ? (array) $pending['data'] : $this->reviewPack($sector, $service, $clusters);
            $structured = $this->ask(QueryClusterReviewAgent::class, $data);
        } catch (\Throwable $exception) {
            Log::warning('Query cluster review failed.', ['service_id' => $service->id, 'error' => $exception->getMessage()]);

            return null;
        }
        if ($structured === null) {
            return ['data' => $data];
        }

        DB::transaction(function () use ($structured, $clusters): void {
            $gone = [];
            foreach ((array) ($structured['merges'] ?? []) as $merge) {
                $into = is_array($merge) && is_int($merge['into_id'] ?? null) ? $clusters->get($merge['into_id']) : null;
                if ($into === null || isset($gone[$into->id])) {
                    continue;
                }
                foreach ((array) ($merge['from_ids'] ?? []) as $fromId) {
                    $from = is_int($fromId) ? $clusters->get($fromId) : null;
                    // Never from a locked or approved (in use by brands) cluster; one page = one page type, so a service
                    // page and a guide are never merged whatever the answer says.
                    if ($from === null || $from->locked || $from->approved || $from->id === $into->id || isset($gone[$from->id])
                        || $from->page_type !== $into->page_type) {
                        continue;
                    }
                    $have = ClusterQuery::query()->where('cluster_id', $into->id)->pluck('query_id')->all();
                    ClusterQuery::query()->where('cluster_id', $from->id)->whereNotIn('query_id', $have ?: [0])->update(['cluster_id' => $into->id]);
                    $from->delete();
                    $gone[$from->id] = true;
                }
            }
            foreach ((array) ($structured['updates'] ?? []) as $update) {
                $cluster = is_array($update) && is_int($update['id'] ?? null) ? $clusters->get($update['id']) : null;
                if ($cluster === null || $cluster->locked || isset($gone[$cluster->id]) || mb_strlen(trim((string) ($update['name'] ?? ''))) < 2
                    || self::catchAll((string) $update['name'])) {
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

        return null;
    }

    /**
     * The review's input: every cluster of the service with its definition, size, demand and most searched queries.
     *
     * @param  Collection<int, Cluster>  $clusters
     * @return array<string, mixed>
     */
    private function reviewPack(ServiceCategory $sector, ServiceCatalogItem $service, Collection $clusters): array
    {
        $examples = collect($this->existingClusters($service))->keyBy('id');
        $impressions = ClusterQuery::query()->join('queries', 'queries.id', '=', 'cluster_queries.query_id')
            ->whereIn('cluster_queries.cluster_id', $clusters->keys())->groupBy('cluster_queries.cluster_id')
            ->selectRaw('cluster_queries.cluster_id, sum(queries.impressions) as total')->pluck('total', 'cluster_queries.cluster_id');

        return [
            'sector' => (string) $sector->name,
            'service' => (string) ($service->primaryName?->raw_label ?? ''),
            'clusters' => $clusters->map(fn (Cluster $cluster): array => [
                'id' => (int) $cluster->id, 'name' => (string) $cluster->name, 'intent' => (string) $cluster->intent,
                'page_type' => (string) $cluster->page_type, 'user_need' => (string) $cluster->user_need,
                'subtopics' => (array) $cluster->subtopics, 'queries' => (int) $cluster->cluster_queries_count,
                'impressions' => (int) ($impressions[$cluster->id] ?? 0),
                'top_queries' => $examples[(int) $cluster->id]['examples'] ?? [], 'locked' => (bool) $cluster->locked,
            ])->values()->all(),
        ];
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
        foreach ($rows as $row) {
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
            if ($ids === [] || ($target === null && (mb_strlen($name) < 2 || self::catchAll($name)))) {
                continue; // a cluster holds at least one real query and one clear need (its topics are asked again)
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
            // Queries the model "adds" are never created: only a real, free query of this service it names joins.
            foreach ((array) ($row['new_queries'] ?? []) as $text) {
                $queryId = $this->existingFreeQuery((string) $text, $byText, $used);
                if ($queryId !== null) {
                    $used[$queryId] = true;
                    $members[] = ['cluster_id' => $cluster->id, 'query_id' => $queryId, 'is_suggested' => false, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            ClusterQuery::query()->insert($members);
            $clusters++;
        }

        return ['clusters' => $clusters, 'suggested' => $suggested, 'placed' => $usedTopics];
    }

    /**
     * A query text the model named: joins only when it is a real, free query of this service in this run (normalized
     * like any query). New texts are never stored — clusters hold collected searches only.
     *
     * @param  array<string, int>  $byText
     * @param  array<int, bool>  $used
     */
    private function existingFreeQuery(string $text, array $byText, array $used): ?int
    {
        $normalized = $this->normalizer->normalize($text);
        $id = $byText[$normalized] ?? null;

        return $id !== null && ! isset($used[$id]) ? (int) $id : null;
    }

    private static function catchAll(string $name): bool
    {
        return preg_match(self::CATCH_ALL, SeoText::fold($name)) === 1;
    }

    /**
     * A finished run approves its sound clusters at once (operator decision 2026-11-18: clustering runs on its own):
     * unlocked, not approved, a clear need (not a catch-all, page type not "other") and at least one collected query.
     * Approved clusters reach the brands of the service (site rows, targets); they are not locked, so the operator can
     * still edit them. Clusters that fail stay pending and are listed on the brand's İçerik fikirleri.
     *
     * @return int approved
     */
    private function autoApprove(ServiceCatalogItem $service): int
    {
        $approved = 0;
        $clusters = Cluster::query()->where('service_id', $service->id)->where('approved', false)->where('locked', false)
            ->where('page_type', '!=', 'other')->get();
        foreach ($clusters as $cluster) {
            $real = ClusterQuery::query()->join('queries', 'queries.id', '=', 'cluster_queries.query_id')
                ->where('cluster_queries.cluster_id', $cluster->id)->where('queries.is_suggested', false)->where('queries.hidden', false)->exists();
            if ($real && ! self::catchAll((string) $cluster->name)) {
                $cluster->forceFill(['approved' => true])->save();
                $approved++;
            }
        }
        if ($approved > 0) {
            // The brands of the service get them at once: targets, and the clusters' rows on their sites (rules, no AI).
            $brandIds = Brand::query()->operational()->whereIn('id', BrandOffering::query()->where('service_catalog_item_id', $service->id)
                ->where('status', 'active')->select('brand_id'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
            foreach ($brandIds as $brandId) {
                app(QueryPipeline::class)->brandTargets($brandId);
            }
            foreach (DigitalAsset::query()->whereIn('brand_id', $brandIds ?: [0])->where('type', 'website')->get() as $site) {
                try {
                    app(ClusterPageMapper::class)->refresh($site, judge: false);
                    SiteFlow::advance($site, setup: false); // Eşleştir for the new clusters (WordPress-paired sites)
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $approved;
    }
}
