<?php

namespace App\Livewire\Operator\Library;

use App\Models\BrandQueryPortfolioItem;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\SearchDemandCluster;
use App\Models\ServiceCatalogItem;
use App\Services\SearchDemand\SearchDemandVisibilityReadService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Sorgu–URL Görünürlük Haritası')]
class SearchDemandVisibilityMapPage extends Component
{
    #[Url(as: 'website', history: true)]
    public string $selectedWebsiteId = '';

    #[Url(history: true)]
    public string $periodStart = '';

    #[Url(history: true)]
    public string $periodEnd = '';

    #[Url(history: true)]
    public string $comparisonStart = '';

    #[Url(history: true)]
    public string $comparisonEnd = '';

    #[Url(history: true)]
    public string $clusterId = '';

    #[Url(history: true)]
    public string $serviceId = '';

    #[Url(history: true)]
    public string $areaId = '';

    #[Url(history: true)]
    public string $observation = 'all';

    #[Url(history: true)]
    public string $search = '';

    public ?int $selectedQueryItemId = null;

    public ?int $selectedPageProfileId = null;

    public function mount(): void
    {
        $end = CarbonImmutable::today('UTC')->subDay();
        $start = $end->subDays(27);
        $comparisonEnd = $start->subDay();
        $comparisonStart = $comparisonEnd->subDays(27);
        $this->periodStart = $this->periodStart ?: $start->toDateString();
        $this->periodEnd = $this->periodEnd ?: $end->toDateString();
        $this->comparisonStart = $this->comparisonStart ?: $comparisonStart->toDateString();
        $this->comparisonEnd = $this->comparisonEnd ?: $comparisonEnd->toDateString();

        if ($this->selectedWebsiteId === '') {
            $websiteId = DigitalAsset::query()->where('type', 'website')->orderBy('name')->value('id');
            $this->selectedWebsiteId = $websiteId !== null ? (string) $websiteId : '';
        }
    }

    public function updatedSelectedWebsiteId(): void
    {
        $this->resetFiltersAndDetails();
    }

    public function showQuery(int $portfolioItemId): void
    {
        BrandQueryPortfolioItem::query()
            ->where('brand_id', $this->website()->brand_id)
            ->findOrFail($portfolioItemId);
        $this->selectedQueryItemId = $portfolioItemId;
    }

    public function showPage(int $pageProfileId): void
    {
        WebsitePageProfile::query()
            ->where('website_asset_id', $this->website()->id)
            ->findOrFail($pageProfileId);
        $this->selectedPageProfileId = $pageProfileId;
    }

    public function closeDetails(): void
    {
        $this->selectedQueryItemId = null;
        $this->selectedPageProfileId = null;
    }

    public function render(SearchDemandVisibilityReadService $visibility): View
    {
        $websites = DigitalAsset::query()
            ->with('brand')
            ->where('type', 'website')
            ->orderBy('name')
            ->get();
        $website = $this->selectedWebsiteId !== ''
            ? $websites->firstWhere('id', (int) $this->selectedWebsiteId)
            : null;
        $result = $this->emptyResult();
        $clusters = collect();
        $services = collect();
        $areas = collect();

        if ($website instanceof DigitalAsset) {
            [$start, $end, $compareStart, $compareEnd] = $this->periods();
            $result = $visibility->read($website, $start, $end, $compareStart, $compareEnd, [
                'cluster_id' => $this->clusterId,
                'service_id' => $this->serviceId,
                'area_id' => $this->areaId,
                'observation' => $this->observation,
                'search' => $this->search,
            ]);
            $clusters = SearchDemandCluster::query()
                ->where('brand_id', $website->brand_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
            $services = ServiceCatalogItem::query()
                ->with('primaryName')
                ->whereHas('brandOfferings', fn ($query) => $query->where('brand_id', $website->brand_id)->where('status', 'active'))
                ->orderBy('id')
                ->get();
            $areas = $website->brand->serviceAreas()->where('status', 'active')->orderBy('id')->get();
        }

        $rows = collect($result['rows']);

        return view('livewire.operator.library.search-demand-visibility-map-page', [
            'websites' => $websites,
            'website' => $website,
            'clusters' => $clusters,
            'services' => $services,
            'areas' => $areas,
            'result' => $result,
            'queryDetailRows' => $this->selectedQueryItemId !== null
                ? $rows->where('portfolio_item_id', $this->selectedQueryItemId)->values()
                : collect(),
            'pageDetailRows' => $this->selectedPageProfileId !== null
                ? $rows->where('page_profile_id', $this->selectedPageProfileId)->values()
                : collect(),
        ]);
    }

    private function website(): DigitalAsset
    {
        return DigitalAsset::query()->where('type', 'website')->findOrFail((int) $this->selectedWebsiteId);
    }

    /** @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function periods(): array
    {
        $end = $this->date($this->periodEnd, CarbonImmutable::today('UTC')->subDay());
        $start = $this->date($this->periodStart, $end->subDays(27));
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        $comparisonEnd = $this->date($this->comparisonEnd, $start->subDay());
        $comparisonStart = $this->date($this->comparisonStart, $comparisonEnd->subDays($start->diffInDays($end)));
        if ($comparisonStart->gt($comparisonEnd)) {
            [$comparisonStart, $comparisonEnd] = [$comparisonEnd, $comparisonStart];
        }

        return [$start, $end, $comparisonStart, $comparisonEnd];
    }

    private function date(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function resetFiltersAndDetails(): void
    {
        $this->clusterId = '';
        $this->serviceId = '';
        $this->areaId = '';
        $this->observation = 'all';
        $this->search = '';
        $this->closeDetails();
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'rows' => [],
            'summary' => [
                'portfolio_queries' => 0,
                'observed_queries' => 0,
                'unobserved_queries' => 0,
                'observed_query_url_pairs' => 0,
                'resolved_urls' => 0,
                'clicks' => null,
                'impressions' => null,
            ],
            'cluster_summary' => [],
            'coverage' => [],
            'truncated' => false,
        ];
    }
}
