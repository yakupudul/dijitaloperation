<?php

namespace App\Services\Brain\Proposals\Kinds;

use App\Ai\Agents\Brain\ClusterLabelAgent;
use App\Ai\Agents\Brain\QueryServiceClassifierAgent;
use App\Models\BrainProposal;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\Brain\BrainAi;
use App\Services\Brain\Clustering\ClusterBuilder;
use App\Services\Brain\Clustering\PageTypes;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\SearchDemand\ManualQueryClusterService;
use App\Support\Ai\AiRouteKeys;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * "Konu kümeleri": one proposal per service that places its not-yet-clustered queries into page-sized clusters
 * (existing clusters are kept and extended). The algorithm decides membership; AI only names the clusters and may
 * correct page type / intent. Approving writes the clusters and memberships into the existing cluster tables.
 */
final class ServiceClustersKind implements ProposalKind
{
    public const string KIND = 'service_clusters';

    private const int MIN_NEW_QUERIES = 3;

    public function __construct(
        private readonly ClusterBuilder $builder,
        private readonly BrainAi $ai,
        private readonly ProposalService $proposals,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Hizmet → sayfa kümeleri';
    }

    public function usesAi(): bool
    {
        return true;
    }

    public function resultNoun(): string
    {
        return 'yeni öneri';
    }

    public function prepare(array $options): int
    {
        $serviceIds = ! empty($options['service_id']) ? [(int) $options['service_id']] : DB::table('search_query_library_item_service as s')
            ->join('service_catalog_items as c', 'c.id', '=', 's.service_catalog_item_id')->where('c.status', 'active')
            ->whereNull('s.library_cluster_id')->groupBy('s.service_catalog_item_id')->havingRaw('count(*) >= ?', [self::MIN_NEW_QUERIES])
            ->orderByRaw('count(*) desc')->limit(25)->pluck('s.service_catalog_item_id')->map('intval')->all();
        $created = 0;
        foreach ($serviceIds as $serviceId) {
            $service = ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')->find($serviceId);
            if ($service === null) {
                continue;
            }
            $result = $this->builder->build($serviceId);
            if ($result['clusters'] === []) {
                continue;
            }
            [$clusters, $source] = $this->nameClusters($service, $result['clusters']);
            $newQueries = array_sum(array_map(fn (array $c): int => count($c['new_query_ids']), $clusters));
            $cohesion = array_sum(array_map(fn (array $c): float => $c['cohesion'] * count($c['query_ids']), $clusters)) / max(1, array_sum(array_map(fn (array $c): int => count($c['query_ids']), $clusters)));
            $name = (string) ($service->primaryName?->raw_label ?? '#'.$serviceId);
            $created += $this->proposals->propose(self::KIND, 'service', $serviceId, null,
                $name.' → '.count($clusters).' küme ('.$newQueries.' sorgu yerleşiyor)', null,
                [
                    'service_id' => $serviceId,
                    'clusters' => array_map(fn (array $c): array => array_diff_key($c, ['texts' => true]), $clusters),
                    'items' => array_map(fn (array $c): string => PageTypes::label($c['page_type']).': '.$c['name'].' ('.count($c['query_ids']).' sorgu'.($c['existing_id'] ? ', mevcut' : '').')', $clusters),
                    'signals' => $result['signals'],
                ],
                'Kullanılan sinyaller: '.implode(', ', $result['signals']).'. Aynı sayfaya girecek sorgular bir kümede; küçük bilgi soruları ana sayfada SSS olarak önerilir.',
                $cohesion, $source) !== null ? 1 : 0;
        }

        return $created;
    }

    public function apply(BrainProposal $proposal, User $actor): void
    {
        $serviceId = (int) $proposal->proposed['service_id'];
        $manual = app(ManualQueryClusterService::class);
        $manual->service($serviceId);
        ServiceCatalogItem::query()->lockForUpdate()->findOrFail($serviceId);
        $manual->assertIdle($serviceId);
        foreach ((array) $proposal->proposed['clusters'] as $cluster) {
            $clusterId = $this->clusterId($serviceId, $cluster, $actor);
            DB::table('search_query_library_item_service')->where('service_catalog_item_id', $serviceId)
                ->whereIn('search_query_library_item_id', array_map('intval', (array) $cluster['new_query_ids']))->whereNull('library_cluster_id')
                ->update(['library_cluster_id' => $clusterId, 'cluster_reviewed_at' => now(), 'cluster_revision' => DB::raw('cluster_revision + 1'), 'updated_at' => now()]);
        }
    }

    /** @param  array<string, mixed>  $cluster */
    private function clusterId(int $serviceId, array $cluster, User $actor): int
    {
        if (! empty($cluster['existing_id'])) {
            $existing = DB::table('library_query_clusters')->where('service_id', $serviceId)->where('status', 'active')->find((int) $cluster['existing_id']);
            if ($existing === null) {
                throw new RuntimeException('Önerideki bir küme artık yok; öneriyi yeniden hazırlayın.');
            }

            return (int) $existing->id;
        }
        $name = mb_substr(trim((string) $cluster['name']), 0, 255);
        $key = LocationOptions::fold($name);
        $found = DB::table('library_query_clusters')->where('service_id', $serviceId)->where('name_key', $key)->first();
        if ($found !== null) {
            DB::table('library_query_clusters')->where('id', $found->id)->update(['status' => 'active', 'updated_at' => now()]);

            return (int) $found->id;
        }

        return (int) DB::table('library_query_clusters')->insertGetId([
            'service_id' => $serviceId, 'name' => $name, 'name_key' => $key, 'description' => '', 'status' => 'active', 'revision' => 1,
            'page_type' => $cluster['page_type'] ?? null, 'intent' => $cluster['intent'] ?? null, 'head_query' => $cluster['head'] ?? null,
            'source' => 'brain', 'signals' => json_encode(['cohesion' => $cluster['cohesion'] ?? null, 'demand' => $cluster['demand'] ?? null]),
            'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * AI names the clusters; on any failure the algorithm's names stay.
     *
     * @param  list<array<string, mixed>>  $clusters
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function nameClusters(ServiceCatalogItem $service, array $clusters): array
    {
        $unnamed = array_values(array_filter($clusters, fn (array $c): bool => empty($c['existing_id'])));
        if ($unnamed === [] || ! $this->ai->available(AiRouteKeys::BRAIN_CLUSTER_LABELS)) {
            return [$clusters, 'system'];
        }
        try {
            $answer = $this->ai->ask(new ClusterLabelAgent, AiRouteKeys::BRAIN_CLUSTER_LABELS, [
                'service' => (string) ($service->primaryName?->raw_label ?? ''),
                'clusters' => array_map(fn (array $c): array => [
                    'key' => $c['key'], 'head' => $c['head'], 'queries' => array_slice($c['texts'], 0, 12), 'intent' => $c['intent'], 'page_type' => $c['page_type'],
                ], $unnamed),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return [$clusters, 'system'];
        }
        $byKey = collect((array) ($answer['items'] ?? []))->keyBy('key');
        $mainTaken = collect($clusters)->contains(fn (array $c): bool => ! empty($c['existing_id']) && $c['page_type'] === 'main');
        foreach ($clusters as &$cluster) {
            $label = $byKey->get($cluster['key']);
            if (! is_array($label) || ! empty($cluster['existing_id'])) {
                continue;
            }
            $name = trim(strip_tags((string) ($label['name'] ?? '')));
            if ($name !== '') {
                $cluster['name'] = mb_substr($name, 0, 120);
            }
            if (in_array($label['intent'] ?? null, QueryServiceClassifierAgent::INTENTS, true)) {
                $cluster['intent'] = $label['intent'];
            }
            $type = $label['page_type'] ?? null;
            if (in_array($type, ClusterLabelAgent::PAGE_TYPES, true) && ! ($type === 'main' && $mainTaken && $cluster['page_type'] !== 'main')) {
                $cluster['page_type'] = $type;
            }
            $mainTaken = $mainTaken || $cluster['page_type'] === 'main';
        }

        return [$clusters, 'ai'];
    }
}
