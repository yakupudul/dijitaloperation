<?php

namespace App\Livewire\Operator\Library;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitor;
use App\Services\SearchDemand\SearchDemandCompetitorLibraryService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Rakip Kütüphanesi')]
class SearchDemandCompetitorLibraryPage extends Component
{
    #[Url(as: 'brand', history: true)]
    public string $selectedBrandId = '';

    #[Url(as: 'website', history: true)]
    public string $selectedWebsiteId = '';

    #[Url(as: 'cluster', history: true)]
    public string $selectedClusterId = '';

    #[Url(as: 'status', history: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'role', history: true)]
    public string $roleFilter = 'all';

    #[Url(history: true)]
    public string $search = '';

    /** @var list<int|string> */
    public array $selectedCompetitorIds = [];

    public string $manualDomain = '';

    public string $manualName = '';

    public string $manualUrls = '';

    public string $manualEntityKind = 'business';

    public bool $manualCommercial = true;

    public bool $manualSerp = false;

    public bool $manualContent = false;

    public string $manualNotes = '';

    /** @var list<int|string> */
    public array $manualServices = [];

    /** @var list<int|string> */
    public array $manualAreas = [];

    /** @var list<int|string> */
    public array $manualClusters = [];

    public ?int $editingCompetitorId = null;

    public string $editName = '';

    public string $editEntityKind = 'unknown';

    public bool $editCommercial = false;

    public bool $editSerp = false;

    public bool $editContent = false;

    public string $editNotes = '';

    /** @var list<int|string> */
    public array $editServices = [];

    /** @var list<int|string> */
    public array $editAreas = [];

    /** @var list<int|string> */
    public array $editClusters = [];

    public string $message = '';

    public function mount(): void
    {
        if ($this->selectedBrandId === '') {
            $brandId = Brand::query()->whereHas('digitalAssets', fn ($query) => $query->where('type', 'website'))
                ->orderBy('name')->value('id');
            $this->selectedBrandId = $brandId !== null ? (string) $brandId : '';
        }
        $this->primeWebsite();
    }

    public function updatedSelectedBrandId(): void
    {
        $this->selectedWebsiteId = '';
        $this->selectedClusterId = '';
        $this->selectedCompetitorIds = [];
        $this->editingCompetitorId = null;
        $this->message = '';
        $this->primeWebsite();
    }

    public function updatedSelectedWebsiteId(): void
    {
        $this->selectedClusterId = '';
        $this->message = '';
    }

    public function importStored(SearchDemandCompetitorLibraryService $service): void
    {
        $this->validate([
            'selectedBrandId' => ['required', 'integer', 'exists:brands,id'],
            'selectedWebsiteId' => ['required', 'integer', 'exists:digital_assets,id'],
            'selectedClusterId' => ['nullable', 'integer', 'exists:search_demand_clusters,id'],
        ]);
        $stats = $service->importStoredDataForSeo(
            $this->website(),
            $this->clusterOrNull(),
            auth()->user(),
        );
        $this->message = sprintf(
            'Saklı DataForSEO gözlemleri işlendi: %d yeni aday, %d mevcut kayıt, %d yeni URL ve %d yeni sorgu ilişkisi.',
            $stats['created'],
            $stats['updated'],
            $stats['urls'],
            $stats['queries'],
        );
    }

    public function addManual(SearchDemandCompetitorLibraryService $service): void
    {
        $this->validate([
            'manualDomain' => ['required', 'string', 'max:1000'],
            'manualName' => ['nullable', 'string', 'max:255'],
            'manualUrls' => ['nullable', 'string', 'max:10000'],
            'manualEntityKind' => ['required', 'in:unknown,business,directory,platform,authority'],
            'manualNotes' => ['nullable', 'string', 'max:4000'],
            'manualServices' => ['array'],
            'manualAreas' => ['array'],
            'manualClusters' => ['array'],
        ]);
        $competitor = $service->addManual($this->brand(), [
            'domain' => $this->manualDomain,
            'display_name' => $this->manualName,
            'urls' => $this->manualUrls,
            'entity_kind' => $this->manualEntityKind,
            'is_commercial_competitor' => $this->manualCommercial,
            'is_serp_competitor' => $this->manualSerp,
            'is_content_competitor' => $this->manualContent,
            'notes' => $this->manualNotes,
            'services' => $this->manualServices,
            'areas' => $this->manualAreas,
            'clusters' => $this->manualClusters,
        ], auth()->user());
        $this->resetManualForm();
        $this->message = sprintf('%s insan kararıyla onaylı rakip olarak kaydedildi.', $competitor->display_name);
    }

    public function reviewSelected(string $decision, SearchDemandCompetitorLibraryService $service): void
    {
        $count = $service->reviewMany(
            $this->brand(),
            $this->selectedCompetitorIds,
            $decision,
            auth()->user(),
        );
        $this->selectedCompetitorIds = [];
        $this->message = $decision === 'approved'
            ? sprintf('%d rakip adayı toplu olarak onaylandı.', $count)
            : sprintf('%d rakip adayı toplu olarak reddedildi.', $count);
    }

    public function reviewCompetitor(
        int $competitorId,
        string $decision,
        SearchDemandCompetitorLibraryService $service,
    ): void {
        $count = $service->reviewMany($this->brand(), [$competitorId], $decision, auth()->user());
        $this->message = $decision === 'approved'
            ? sprintf('%d rakip adayı onaylandı.', $count)
            : sprintf('%d rakip adayı reddedildi.', $count);
    }

    public function editCompetitor(int $competitorId): void
    {
        $competitor = $this->competitor($competitorId)->load(['services', 'serviceAreas', 'clusters']);
        $this->editingCompetitorId = $competitor->id;
        $this->editName = $competitor->display_name;
        $this->editEntityKind = $competitor->entity_kind;
        $this->editCommercial = $competitor->is_commercial_competitor;
        $this->editSerp = $competitor->is_serp_competitor;
        $this->editContent = $competitor->is_content_competitor;
        $this->editNotes = (string) $competitor->notes;
        $this->editServices = $competitor->services->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->editAreas = $competitor->serviceAreas->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->editClusters = $competitor->clusters->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->message = '';
    }

    public function saveCompetitor(SearchDemandCompetitorLibraryService $service): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editEntityKind' => ['required', 'in:unknown,business,directory,platform,authority'],
            'editNotes' => ['nullable', 'string', 'max:4000'],
            'editServices' => ['array'],
            'editAreas' => ['array'],
            'editClusters' => ['array'],
        ]);
        if ($this->editingCompetitorId === null) {
            return;
        }
        $competitor = $service->updateClassification($this->competitor($this->editingCompetitorId), [
            'display_name' => $this->editName,
            'entity_kind' => $this->editEntityKind,
            'is_commercial_competitor' => $this->editCommercial,
            'is_serp_competitor' => $this->editSerp,
            'is_content_competitor' => $this->editContent,
            'notes' => $this->editNotes,
            'services' => $this->editServices,
            'areas' => $this->editAreas,
            'clusters' => $this->editClusters,
        ], auth()->user());
        $this->editingCompetitorId = null;
        $this->message = sprintf('%s sınıflandırması ve kapsam ilişkileri güncellendi.', $competitor->display_name);
    }

    public function cancelEdit(): void
    {
        $this->editingCompetitorId = null;
    }

    public function render(): View
    {
        $brands = Brand::query()->whereHas('digitalAssets', fn ($query) => $query->where('type', 'website'))
            ->orderBy('name')->get();
        $brand = $this->selectedBrandId !== '' ? $brands->firstWhere('id', (int) $this->selectedBrandId) : null;
        $websites = collect();
        $clusters = collect();
        $services = collect();
        $areas = collect();
        $competitors = collect();

        if ($brand instanceof Brand) {
            $websites = $brand->digitalAssets()->where('type', 'website')->orderBy('name')->get();
            $clusters = $brand->searchDemandClusters()->where('status', 'active')->orderBy('name')->get();
            $services = $brand->offerings()->with('catalogItem.primaryName')->where('status', 'active')->whereNotNull('service_catalog_item_id')
                ->get()->pluck('catalogItem')->filter()->unique('id')->sortBy(
                    fn ($service): string => mb_strtolower((string) $service->primaryName?->raw_label),
                )->values();
            $areas = $brand->serviceAreas()->where('status', 'active')->orderBy('priority_rank')->get();
            $competitors = SearchDemandCompetitor::query()
                ->with([
                    'urls',
                    'sources:id,search_demand_competitor_id,source_type,provider',
                    'services.primaryName',
                    'serviceAreas',
                    'clusters',
                    'queries.libraryItem',
                ])
                ->withCount(['urls', 'sources', 'queries'])
                ->where('brand_id', $brand->id)
                ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
                ->when($this->roleFilter === 'commercial', fn ($query) => $query->where('is_commercial_competitor', true))
                ->when($this->roleFilter === 'serp', fn ($query) => $query->where('is_serp_competitor', true))
                ->when($this->roleFilter === 'content', fn ($query) => $query->where('is_content_competitor', true))
                ->when(in_array($this->roleFilter, SearchDemandCompetitor::ENTITY_KINDS, true), fn ($query) => $query->where('entity_kind', $this->roleFilter))
                ->when(trim($this->search) !== '', function ($query): void {
                    $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%';
                    $query->where(fn ($nested) => $nested
                        ->whereLike('display_name', $term, caseSensitive: false)
                        ->orWhereLike('normalized_domain', $term, caseSensitive: false));
                })
                ->orderByRaw("case status when 'pending' then 0 when 'approved' then 1 else 2 end")
                ->orderByDesc('last_observed_at')
                ->limit(200)
                ->get();
        }

        return view('livewire.operator.library.search-demand-competitor-library-page', compact(
            'brands', 'brand', 'websites', 'clusters', 'services', 'areas', 'competitors',
        ));
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail((int) $this->selectedBrandId);
    }

    private function website(): DigitalAsset
    {
        return DigitalAsset::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('type', 'website')
            ->findOrFail((int) $this->selectedWebsiteId);
    }

    private function clusterOrNull(): ?SearchDemandCluster
    {
        if ($this->selectedClusterId === '') {
            return null;
        }

        return SearchDemandCluster::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('status', 'active')
            ->findOrFail((int) $this->selectedClusterId);
    }

    private function competitor(int $competitorId): SearchDemandCompetitor
    {
        return SearchDemandCompetitor::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->findOrFail($competitorId);
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

    private function resetManualForm(): void
    {
        $this->manualDomain = '';
        $this->manualName = '';
        $this->manualUrls = '';
        $this->manualEntityKind = 'business';
        $this->manualCommercial = true;
        $this->manualSerp = false;
        $this->manualContent = false;
        $this->manualNotes = '';
        $this->manualServices = [];
        $this->manualAreas = [];
        $this->manualClusters = [];
    }
}
