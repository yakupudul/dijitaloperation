<?php

namespace App\Services\Website;

use App\Models\BrandOffering;
use App\Models\BrandQueryPortfolioItem;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandPageOwnership;
use App\Services\SearchDemand\SearchDemandPageOwnershipService;
use Illuminate\Support\Collection;

final class WebsiteCoverageService
{
    public function __construct(private readonly SearchDemandPageOwnershipService $ownership) {}

    /** @param Collection<int, WebsitePageProfile> $profiles @return array<string, mixed> */
    public function assess(DigitalAsset $website, Collection $profiles): array
    {
        $items = BrandQueryPortfolioItem::query()->with(['libraryItem', 'services.names', 'clusterMembership', 'serviceAreas', 'brand.serviceAreas'])
            ->where('brand_id', $website->brand_id)->where('status', 'active')
            ->whereHas('assetStates', fn ($query) => $query->where('digital_asset_id', $website->id)->where('status', 'active'))
            ->orderBy('id')->limit(3001)->get();
        $queryLimitReached = $items->count() > 3000;
        $items = $items->take(3000);
        $offerings = BrandOffering::query()->with('primaryName')->where('brand_id', $website->brand_id)
            ->where('status', 'active')->orderBy('priority_rank')->orderBy('id')->get();
        $clusters = SearchDemandCluster::query()->where('brand_id', $website->brand_id)->where('status', 'active')
            ->whereNotNull('content_target_cluster')->where('content_target_cluster', '!=', '')
            ->whereIn('id', $items->pluck('clusterMembership.search_demand_cluster_id')->filter()->unique())
            ->orderBy('id')->limit(101)->get();
        $clusterLimitReached = $clusters->count() > 100;
        $clusters = $clusters->take(100);
        $owners = SearchDemandPageOwnership::query()->with('pageProfile')
            ->where('digital_asset_id', $website->id)->where('brand_id', $website->brand_id)
            ->whereIn('search_demand_cluster_id', $clusters->modelKeys())->get()->keyBy('search_demand_cluster_id');
        $rows = [];
        foreach ($clusters as $cluster) {
            $members = $items->filter(fn (BrandQueryPortfolioItem $item): bool => (int) $item->clusterMembership?->search_demand_cluster_id === (int) $cluster->id);
            $serviceIds = $members->flatMap(fn ($item) => $item->services->modelKeys())->unique()->values();
            $owner = $owners->get($cluster->id);
            $candidates = $this->ownership->coverageCandidates($website, $cluster, $profiles, $members);
            $gate = $owner?->pageProfile !== null ? $this->ownership->technicalGate($website, $cluster, $owner->pageProfile) : null;
            $state = match (true) {
                $owner?->status === 'excluded' => 'excluded',
                $owner?->status === 'verified_owner' && $gate !== null => $gate['state'] === 'eligible' ? 'covered' : 'repair_or_verify',
                $owner?->status === 'no_suitable_url' => 'human_confirmed_gap',
                $candidates !== [] => 'candidate_review',
                default => 'unknown',
            };
            $rows[] = [
                'cluster_id' => $cluster->id, 'cluster_name' => $cluster->name,
                'cluster_version' => $cluster->version, 'state' => $state,
                'query_count' => $members->count(),
                'queries' => $members->take(10)->map(fn ($item) => $item->effectiveQueryText())->values()->all(),
                'service_ids' => $serviceIds->all(),
                'service_priority' => $offerings->whereIn('service_catalog_item_id', $serviceIds)->min('priority_rank'),
                'service_names' => $members->flatMap(fn ($item) => $item->services)
                    ->flatMap(fn ($service) => $service->names->where('is_active', true)->pluck('raw_label'))->unique()->take(20)->values()->all(),
                'locations' => $members->flatMap(fn ($item) => ($item->area_scope === 'selected_areas' ? $item->serviceAreas : $item->brand->serviceAreas)->where('status', 'active'))
                    ->flatMap(fn ($area) => array_filter([$area->city_name, $area->district_name]))->unique()->take(20)->values()->all(),
                'owner_url' => $owner?->target_url, 'owner_id' => $owner?->id,
                'owner_version' => $owner?->version, 'owner_locked' => (bool) $owner?->is_locked,
                'technical_state' => $gate['state'] ?? 'unknown', 'technical_checks' => $gate['checks'] ?? [],
                'candidates' => $candidates,
                'explanation' => match ($state) {
                    'covered' => 'İnsan tarafından doğrulanmış hedef var; içerik kalitesi ayrıca incelenmelidir.',
                    'repair_or_verify' => 'Doğrulanmış hedef korunuyor. Teknik engeli giderin veya eksik gözlemi tamamlayın.',
                    'candidate_review' => 'Başlık/URL eşleşen sayfalar var. Ana hedef ile destek içeriklerini inceleyin; eşleşme sahiplik kanıtı değildir.',
                    'human_confirmed_gap' => 'Operatör uygun hedef olmadığını kaydetmiş. Yeni sayfa kapsamını mevcut adaylarla birlikte planlayın.',
                    'excluded' => 'Küme operatör kararıyla kapsam dışı.',
                    default => 'İncelenen envanterde aday doğrulanamadı. Bu, yeni sayfa gerektiğini kanıtlamaz.',
                },
            ];
        }
        $serviceRows = $offerings->map(function ($offering) use ($items): array {
            $members = $items->filter(fn ($item) => $offering->service_catalog_item_id !== null
                && $item->services->contains('id', $offering->service_catalog_item_id));

            return [
                'offering_id' => $offering->id, 'service_id' => $offering->service_catalog_item_id,
                'name' => $offering->primaryName?->raw_label ?: 'Hizmet #'.$offering->id,
                'priority' => $offering->priority_rank, 'query_count' => $members->count(),
                'cluster_count' => $members->pluck('clusterMembership.search_demand_cluster_id')->filter()->unique()->count(),
                'state' => $offering->service_catalog_item_id === null ? 'unlinked_service'
                    : ($members->isEmpty() ? 'no_active_queries' : 'available'),
            ];
        })->all();

        return [
            'services' => $serviceRows,
            'clusters' => collect($rows)->sortBy(fn ($row) => [$row['state'] === 'repair_or_verify' ? 0 : 1, $row['service_priority'] ?? PHP_INT_MAX, $row['cluster_id']])->values()->all(),
            'unclustered_query_count' => $items->filter(fn ($item) => $item->clusterMembership === null)->count(),
            'query_limit_reached' => $queryLimitReached, 'cluster_limit_reached' => $clusterLimitReached,
        ];
    }
}
