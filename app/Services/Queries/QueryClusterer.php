<?php

namespace App\Services\Queries;

use App\Ai\Agents\Queries\QueryClusterAgent;
use App\Models\CoreAssetBinding;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Brain\BrainAi;
use App\Services\Brain\Clustering\ClusterBuilder;
use App\Support\Ai\AiRouteKeys;
use App\Support\Options\LocationOptions;
use App\Support\ServiceScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Topic clusters per sector × service over the core queries (library_query_clusters; membership on the query-service
 * link). The AI groups one service's queries into page-sized topics (one page answers one cluster) and guesses each
 * cluster's page type (hizmet / blog / sss / karsilastirma); ClusterPageResearch then checks that guess against
 * Google's top 10. Operator-reviewed memberships (cluster_reviewed_at) never move; operator-made clusters keep their
 * name. Without an AI route the Brain's similarity algorithm (ClusterBuilder) places the queries instead.
 * Only services with queries seen on an operational brand's account are clustered (paid call).
 */
final class QueryClusterer
{
    public const array DECISIONS = ['hizmet', 'blog', 'sss', 'karsilastirma'];

    public function __construct(
        private readonly BrainAi $ai,
        private readonly ClusterBuilder $builder,
    ) {}

    /**
     * Services whose membership changed since their last clustering (weekly), most demand first.
     *
     * @return array{services: int, clusters: int, placed: int}
     */
    public function clusterDue(?int $limit = null): array
    {
        $stats = ['services' => 0, 'clusters' => 0, 'placed' => 0];
        foreach ($this->eligibleServices()->take($limit ?? (int) config('moxdop-queries.cluster_services_per_run', 25)) as $serviceId) {
            $key = 'queries:clusters:'.$serviceId;
            $fingerprint = $this->membershipFingerprint($serviceId);
            if (Cache::get($key) === $fingerprint) {
                continue;
            }
            $result = $this->clusterService($serviceId);
            $stats['services']++;
            $stats['clusters'] += $result['clusters'];
            $stats['placed'] += $result['placed'];
            Cache::forever($key, $this->membershipFingerprint($serviceId));
        }

        return $stats;
    }

    /**
     * @return array{clusters: int, placed: int, source: string}
     */
    public function clusterService(int $serviceId): array
    {
        $service = ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')->find($serviceId);
        if ($service === null) {
            return ['clusters' => 0, 'placed' => 0, 'source' => 'none'];
        }
        $members = $this->members($serviceId);
        $free = $members->whereNull('reviewed_at')->sortByDesc('demand')->take(max(10, (int) config('moxdop-queries.cluster_max_queries', 250)));
        if ($free->count() < (int) config('moxdop-queries.cluster_min_queries', 3)) {
            return ['clusters' => 0, 'placed' => 0, 'source' => 'none'];
        }
        if ($this->ai->available(AiRouteKeys::QUERIES_CLUSTERING)) {
            try {
                return $this->byAi($service, $members, $free) + ['source' => 'ai'];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->byAlgorithm($serviceId) + ['source' => 'system'];
    }

    /**
     * @param  Collection<int, object>  $members
     * @param  Collection<int, object>  $free
     * @return array{clusters: int, placed: int}
     */
    private function byAi(ServiceCatalogItem $service, Collection $members, Collection $free): array
    {
        $existing = DB::table('library_query_clusters')->where('service_id', $service->id)->where('status', 'active')->get()->keyBy('name_key');
        $answer = $this->ai->ask(new QueryClusterAgent, AiRouteKeys::QUERIES_CLUSTERING, [
            'sector' => ServiceCategory::query()->where('code', $service->sector)->value('name'),
            'service' => (string) ($service->primaryName?->raw_label ?? ''),
            'clusters' => $existing->pluck('name')->values()->all(),
            'queries' => $free->map(fn ($m): array => ['id' => (int) $m->item_id, 'text' => (string) $m->text, 'demand' => (int) $m->demand])->values()->all(),
        ], 300);
        $allowed = $free->keyBy('item_id');
        $placedIds = [];
        $clusters = 0;
        foreach ((array) ($answer['clusters'] ?? []) as $cluster) {
            $name = mb_substr(trim(strip_tags((string) ($cluster['name'] ?? ''))), 0, 120);
            $ids = array_values(array_filter(array_unique(array_map('intval', (array) ($cluster['query_ids'] ?? []))),
                fn (int $id): bool => $allowed->has($id) && ! isset($placedIds[$id])));
            if ($name === '' || $ids === []) {
                continue;
            }
            $headId = (int) ($cluster['head_query_id'] ?? 0);
            $head = $allowed->get(in_array($headId, $ids, true) ? $headId : $ids[0]);
            $decision = in_array($cluster['page_type'] ?? null, self::DECISIONS, true) ? $cluster['page_type'] : null;
            $clusterId = $this->upsertCluster((int) $service->id, $name, (string) $head->text, $decision, 'ai', mb_substr((string) ($cluster['reason'] ?? ''), 0, 300));
            $this->place((int) $service->id, $clusterId, $ids);
            foreach ($ids as $id) {
                $placedIds[$id] = true;
            }
            $clusters++;
        }

        return ['clusters' => $clusters, 'placed' => count($placedIds)];
    }

    /** @return array{clusters: int, placed: int} */
    private function byAlgorithm(int $serviceId): array
    {
        $result = $this->builder->build($serviceId);
        $placed = 0;
        foreach ($result['clusters'] as $cluster) {
            $decision = match ($cluster['page_type']) {
                'main', 'landing' => 'hizmet',
                'faq' => 'sss',
                default => 'blog',
            };
            $clusterId = ! empty($cluster['existing_id'])
                ? (int) $cluster['existing_id']
                : $this->upsertCluster($serviceId, (string) $cluster['name'], (string) $cluster['head'], $decision, 'brain', null, (string) $cluster['page_type'], (string) $cluster['intent']);
            $placed += $this->place($serviceId, $clusterId, array_map('intval', (array) $cluster['new_query_ids']));
        }

        return ['clusters' => count($result['clusters']), 'placed' => $placed];
    }

    /**
     * Brain page type of a decision (one main page per service; a second service-page cluster is a landing page).
     */
    private function brainPageType(int $serviceId, ?string $decision, ?int $exceptId = null): ?string
    {
        return match ($decision) {
            'hizmet' => DB::table('library_query_clusters')->where('service_id', $serviceId)->where('status', 'active')->where('page_type', 'main')
                ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->exists() ? 'landing' : 'main',
            'sss' => 'faq',
            'blog', 'karsilastirma' => 'support',
            default => null,
        };
    }

    private function upsertCluster(int $serviceId, string $name, string $head, ?string $decision, string $source, ?string $reason, ?string $pageType = null, ?string $intent = null): int
    {
        $key = LocationOptions::fold($name);
        $found = DB::table('library_query_clusters')->where('service_id', $serviceId)->where('name_key', $key)->first();
        $now = now();
        if ($found !== null) {
            $update = ['status' => 'active', 'updated_at' => $now];
            if (in_array($found->source, ['ai', 'system', 'brain'], true)) {
                $update['head_query'] = $head;
                $update['page_type'] = $found->page_type ?? $pageType ?? $this->brainPageType($serviceId, $decision, (int) $found->id);
                $update['intent'] = $intent ?? $found->intent;
            }
            if ($decision !== null && in_array($found->decision_source, [null, 'ai'], true)) {
                $update += ['page_decision' => $decision, 'decision_source' => 'ai'];
            }
            DB::table('library_query_clusters')->where('id', $found->id)->update($update);

            return (int) $found->id;
        }

        return (int) DB::table('library_query_clusters')->insertGetId([
            'service_id' => $serviceId, 'name' => $name, 'name_key' => $key, 'description' => '', 'status' => 'active', 'revision' => 1,
            'head_query' => $head, 'source' => $source, 'page_decision' => $decision, 'decision_source' => $decision !== null ? 'ai' : null,
            'page_type' => $pageType ?? $this->brainPageType($serviceId, $decision), 'intent' => $intent,
            'signals' => $reason !== null ? json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Membership for queries the operator has not placed by hand.
     *
     * @param  list<int>  $itemIds
     */
    private function place(int $serviceId, int $clusterId, array $itemIds): int
    {
        if ($itemIds === []) {
            return 0;
        }

        return DB::table('search_query_library_item_service')->where('service_catalog_item_id', $serviceId)
            ->whereIn('search_query_library_item_id', $itemIds)->whereNull('cluster_reviewed_at')
            ->where(fn ($q) => $q->whereNull('library_cluster_id')->orWhere('library_cluster_id', '!=', $clusterId))
            ->update(['library_cluster_id' => $clusterId, 'cluster_revision' => DB::raw('cluster_revision + 1'), 'updated_at' => now()]);
    }

    /** @return Collection<int, object{item_id: int, text: string, demand: int, reviewed_at: ?string, cluster_id: ?int}> */
    private function members(int $serviceId): Collection
    {
        return DB::table('search_query_library_item_service as p')
            ->join('search_query_library_items as q', 'q.id', '=', 'p.search_query_library_item_id')
            ->where('p.service_catalog_item_id', $serviceId)->where('q.status', 'active')->whereNull('q.deleted_at')->where('q.is_branded', false)
            ->get(['q.id as item_id', 'q.canonical_text as text', DB::raw('(q.gsc_impressions + q.ads_impressions + q.gbp_impressions + 5 * q.ads_clicks) as demand'),
                'p.cluster_reviewed_at as reviewed_at', 'p.library_cluster_id as cluster_id'])
            ->map(function ($row) {
                $row->item_id = (int) $row->item_id;
                $row->demand = (int) $row->demand;

                return $row;
            });
    }

    /**
     * Services with enough queries seen on an operational brand's accounts, most demand first.
     *
     * @return Collection<int, int>
     */
    public function eligibleServices(): Collection
    {
        $assetIds = app(ServiceScope::class)->operationalAssetIds();
        $resourceIds = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds ?: [0])->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id')->all();

        return DB::table('search_query_library_item_service as p')
            ->join('search_query_library_items as q', 'q.id', '=', 'p.search_query_library_item_id')
            ->join('service_catalog_items as c', 'c.id', '=', 'p.service_catalog_item_id')
            ->where('c.status', 'active')->where('q.status', 'active')->whereNull('q.deleted_at')
            ->whereExists(fn ($w) => $w->selectRaw('1')->from('query_variants as v')->whereColumn('v.search_query_library_item_id', 'q.id')
                ->where(fn ($s) => $s->whereIn('v.external_resource_id', $resourceIds ?: [0])->orWhereIn('v.digital_asset_id', $assetIds ?: [0])))
            ->groupBy('p.service_catalog_item_id')->havingRaw('count(*) >= ?', [(int) config('moxdop-queries.cluster_min_queries', 3)])
            ->orderByRaw('sum(q.gsc_impressions + q.ads_impressions + q.gbp_impressions) desc')
            ->pluck('p.service_catalog_item_id')->map('intval');
    }

    private function membershipFingerprint(int $serviceId): string
    {
        $row = DB::table('search_query_library_item_service')->where('service_catalog_item_id', $serviceId)->whereNull('cluster_reviewed_at')
            ->selectRaw('count(*) as n, max(id) as m, sum(case when library_cluster_id is null then 1 else 0 end) as free')->first();

        return hash('sha256', json_encode([(int) $row->n, (int) $row->m, (int) $row->free], JSON_THROW_ON_ERROR));
    }
}
