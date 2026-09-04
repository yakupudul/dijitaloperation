<?php

namespace App\Livewire\Operator\Library;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitorPageObservation;
use App\Services\Async\AsyncOperationService;
use App\Services\SearchDemand\SearchDemandCompetitorPageCollectionService;
use App\Support\Async\AsyncOperationTypes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Rakip Sayfa Toplama')]
class SearchDemandCompetitorPagesPage extends Component
{
    #[Url(as: 'brand', history: true)]
    public string $selectedBrandId = '';

    #[Url(as: 'website', history: true)]
    public string $selectedWebsiteId = '';

    #[Url(as: 'cluster', history: true)]
    public string $selectedClusterId = '';

    public int $maxUrls = 10;

    public string $message = '';

    public string $messageTone = 'info';

    public function mount(): void
    {
        if ($this->selectedBrandId === '') {
            $brandId = Brand::query()
                ->whereHas('digitalAssets', fn ($query) => $query->where('type', 'website'))
                ->orderBy('name')
                ->value('id');
            $this->selectedBrandId = $brandId !== null ? (string) $brandId : '';
        }
        $this->primeWebsite();
        $this->primeCluster();
    }

    public function updatedSelectedBrandId(): void
    {
        $this->selectedWebsiteId = '';
        $this->selectedClusterId = '';
        $this->message = '';
        $this->primeWebsite();
        $this->primeCluster();
    }

    public function updatedSelectedWebsiteId(): void
    {
        $this->message = '';
    }

    public function updatedSelectedClusterId(): void
    {
        $this->message = '';
    }

    public function queueCollection(
        SearchDemandCompetitorPageCollectionService $collection,
        AsyncOperationService $async,
    ): void {
        $this->validate([
            'selectedBrandId' => ['required', 'integer', 'exists:brands,id'],
            'selectedWebsiteId' => ['required', 'integer', 'exists:digital_assets,id'],
            'selectedClusterId' => ['required', 'integer', 'exists:search_demand_clusters,id'],
            'maxUrls' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $website = $this->website();
        $cluster = $this->cluster();
        $preview = $collection->preview($cluster, $this->maxUrls);
        if ($preview === []) {
            $this->messageTone = 'error';
            $this->message = 'Bu kümede URL kaydı bulunan onaylı ve ilişkilendirilmiş rakip yok.';

            return;
        }

        $result = $async->queueSearchDemandCompetitorPageCollection(
            $website,
            $cluster,
            $this->maxUrls,
            auth()->user(),
        );
        $run = $result['run'] ?? $result['existing_run'] ?? null;
        $this->messageTone = ($result['ok'] ?? false) ? 'success' : 'error';
        $this->message = $run instanceof Run
            ? sprintf('Rakip sayfa toplama #%d kuyruğa alındı. Activity ekranından izleyebilirsiniz.', $run->id)
            : (string) ($result['message'] ?? 'Toplama kuyruğa alınamadı.');
    }

    public function render(SearchDemandCompetitorPageCollectionService $collection): View
    {
        $brands = Brand::query()
            ->whereHas('digitalAssets', fn ($query) => $query->where('type', 'website'))
            ->orderBy('name')
            ->get();
        $brand = $this->selectedBrandId !== '' ? $brands->firstWhere('id', (int) $this->selectedBrandId) : null;
        $websites = collect();
        $clusters = collect();
        $preview = [];
        $latestRun = null;
        $observations = collect();

        if ($brand instanceof Brand) {
            $websites = $brand->digitalAssets()->where('type', 'website')->orderBy('name')->get();
            $clusters = $brand->searchDemandClusters()
                ->where('status', 'active')
                ->whereNotNull('content_target_cluster')
                ->where('content_target_cluster', '!=', '')
                ->orderBy('name')
                ->get();
            if ($this->selectedClusterId !== '' && $clusters->contains('id', (int) $this->selectedClusterId)) {
                $cluster = $clusters->firstWhere('id', (int) $this->selectedClusterId);
                $preview = $collection->preview($cluster, $this->maxUrls);
                if ($this->selectedWebsiteId !== '') {
                    $latestRun = Run::query()
                        ->where('digital_asset_id', (int) $this->selectedWebsiteId)
                        ->where('module_id', AsyncOperationTypes::MODULE_SEARCH_DEMAND_COMPETITOR_PAGE_COLLECTION)
                        ->where('metadata->cluster_id', (int) $this->selectedClusterId)
                        ->latest('id')
                        ->first();
                }
                $observations = SearchDemandCompetitorPageObservation::query()
                    ->with(['runItem.competitor', 'contentSource'])
                    ->whereHas('runItem', fn ($query) => $query
                        ->where('search_demand_cluster_id', (int) $this->selectedClusterId)
                        ->whereHas('competitor', fn ($competitor) => $competitor->where('brand_id', $brand->id)))
                    ->latest('observed_at')
                    ->latest('id')
                    ->limit(100)
                    ->get()
                    ->map(function (SearchDemandCompetitorPageObservation $observation): array {
                        $content = $observation->contentSource ?? $observation;

                        return [
                            'observation' => $observation,
                            'competitor' => $observation->runItem?->competitor,
                            'title' => $content->title,
                            'h1' => $content->h1,
                            'headings' => is_array($content->headings) ? $content->headings : [],
                            'schema' => is_array($content->schema_summary) ? $content->schema_summary : [],
                            'internal_links' => is_array($content->internal_links) ? $content->internal_links : [],
                            'external_links' => is_array($content->external_links) ? $content->external_links : [],
                            'services' => is_array($content->service_expressions) ? $content->service_expressions : [],
                            'locations' => is_array($content->location_expressions) ? $content->location_expressions : [],
                        ];
                    });
            }
        }

        return view('livewire.operator.library.search-demand-competitor-pages-page', compact(
            'brands', 'brand', 'websites', 'clusters', 'preview', 'latestRun', 'observations',
        ));
    }

    private function website(): DigitalAsset
    {
        return DigitalAsset::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('type', 'website')
            ->findOrFail((int) $this->selectedWebsiteId);
    }

    private function cluster(): SearchDemandCluster
    {
        return SearchDemandCluster::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('status', 'active')
            ->whereNotNull('content_target_cluster')
            ->where('content_target_cluster', '!=', '')
            ->findOrFail((int) $this->selectedClusterId);
    }

    private function primeWebsite(): void
    {
        if ($this->selectedBrandId === '') {
            return;
        }
        $websiteId = DigitalAsset::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('type', 'website')
            ->orderBy('name')
            ->value('id');
        $this->selectedWebsiteId = $websiteId !== null ? (string) $websiteId : '';
    }

    private function primeCluster(): void
    {
        if ($this->selectedBrandId === '') {
            return;
        }
        $clusterId = SearchDemandCluster::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('status', 'active')
            ->whereNotNull('content_target_cluster')
            ->where('content_target_cluster', '!=', '')
            ->orderBy('name')
            ->value('id');
        $this->selectedClusterId = $clusterId !== null ? (string) $clusterId : '';
    }
}
