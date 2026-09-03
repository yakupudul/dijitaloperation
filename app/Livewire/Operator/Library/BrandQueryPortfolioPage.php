<?php

namespace App\Livewire\Operator\Library;

use App\Models\Brand;
use App\Models\BrandQueryPortfolioItem;
use App\Services\SearchDemand\BrandQueryPortfolioService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Marka Sorgu Portföyü')]
class BrandQueryPortfolioPage extends Component
{
    #[Url(as: 'brand', history: true)]
    public string $selectedBrandId = '';

    #[Url(as: 'website', history: true)]
    public string $selectedWebsiteId = '';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(as: 'status', history: true)]
    public string $portfolioStatus = 'active';

    public string $brandQueryText = '';

    public string $brandQueryServiceId = '';

    public string $brandQueryLanguage = 'tr';

    public string $brandQueryMarket = 'TR';

    public string $brandQueryDemandFamily = '';

    public string $brandQueryLocationScope = 'none';

    public string $brandQueryLocationValue = '';

    public bool $brandQueryIsBranded = false;

    public ?int $editingPortfolioItemId = null;

    public string $overrideQueryText = '';

    public string $overrideLanguage = '';

    public string $overrideMarket = '';

    public string $overrideDemandFamily = '';

    public string $overrideLocationScope = '';

    public string $overrideLocationValue = '';

    public string $overrideIsBranded = 'inherit';

    public ?int $areaPortfolioItemId = null;

    public string $areaMode = 'all';

    /** @var list<int|string> */
    public array $selectedAreaIds = [];

    public string $message = '';

    public string $messageTone = 'success';

    public function mount(): void
    {
        if ($this->selectedBrandId === '') {
            $brandId = Brand::query()->orderBy('name')->value('id');
            $this->selectedBrandId = $brandId !== null ? (string) $brandId : '';
        }

        $this->ensureWebsiteBelongsToBrand();
    }

    public function updatedSelectedBrandId(): void
    {
        $this->selectedWebsiteId = '';
        $this->editingPortfolioItemId = null;
        $this->areaPortfolioItemId = null;
        $this->message = '';
    }

    public function inheritQueries(BrandQueryPortfolioService $portfolios): void
    {
        $result = $portfolios->inheritForBrand($this->brand(), auth()->user());
        $this->messageTone = 'success';
        $this->message = sprintf(
            '%d uygun global sorgudan %d yeni ilişki eklendi; %d ilişki zaten vardı.',
            $result['eligible'],
            $result['created'],
            $result['existing'],
        );
    }

    public function addBrandQuery(BrandQueryPortfolioService $portfolios): void
    {
        $this->validate([
            'brandQueryText' => ['required', 'string', 'max:1000'],
            'brandQueryServiceId' => ['nullable', 'integer', 'exists:service_catalog_items,id'],
            'brandQueryLanguage' => ['nullable', 'string', 'max:32'],
            'brandQueryMarket' => ['nullable', 'string', 'max:32'],
            'brandQueryDemandFamily' => ['nullable', 'string', 'max:255'],
            'brandQueryLocationScope' => ['required', 'in:none,country,city,district,pattern'],
            'brandQueryLocationValue' => ['nullable', 'string', 'max:255'],
            'brandQueryIsBranded' => ['boolean'],
        ]);

        $result = $portfolios->addBrandQuery($this->brand(), $this->brandQueryText, [
            'service_catalog_item_id' => $this->brandQueryServiceId !== '' ? (int) $this->brandQueryServiceId : null,
            'language_code' => $this->brandQueryLanguage,
            'market_code' => $this->brandQueryMarket,
            'demand_family' => $this->brandQueryDemandFamily,
            'location_scope' => $this->brandQueryLocationScope,
            'location_value' => $this->brandQueryLocationValue,
            'is_branded' => $this->brandQueryIsBranded,
        ], auth()->user());

        $this->brandQueryText = '';
        $this->brandQueryDemandFamily = '';
        $this->brandQueryLocationValue = '';
        $this->brandQueryIsBranded = false;
        $this->messageTone = 'success';
        $this->message = $result['created']
            ? 'Markaya özel sorgu portföye eklendi.'
            : 'Aynı markaya özel sorgu zaten portföyde bulunuyor.';
    }

    public function editOverrides(int $itemId): void
    {
        $item = $this->portfolioItem($itemId);
        $this->editingPortfolioItemId = $item->id;
        $this->overrideQueryText = (string) $item->query_text_override;
        $this->overrideLanguage = (string) $item->language_code;
        $this->overrideMarket = (string) $item->market_code;
        $this->overrideDemandFamily = (string) $item->demand_family_override;
        $this->overrideLocationScope = (string) $item->location_scope_override;
        $this->overrideLocationValue = (string) $item->location_value_override;
        $this->overrideIsBranded = $item->is_branded_override === null
            ? 'inherit'
            : ($item->is_branded_override ? 'yes' : 'no');
    }

    public function cancelOverrides(): void
    {
        $this->editingPortfolioItemId = null;
    }

    public function saveOverrides(BrandQueryPortfolioService $portfolios): void
    {
        if ($this->editingPortfolioItemId === null) {
            return;
        }

        $this->validate([
            'overrideQueryText' => ['nullable', 'string', 'max:1000'],
            'overrideLanguage' => ['nullable', 'string', 'max:32'],
            'overrideMarket' => ['nullable', 'string', 'max:32'],
            'overrideDemandFamily' => ['nullable', 'string', 'max:255'],
            'overrideLocationScope' => ['nullable', 'in:none,country,city,district,pattern'],
            'overrideLocationValue' => ['nullable', 'string', 'max:255'],
            'overrideIsBranded' => ['required', 'in:inherit,yes,no'],
        ]);

        $portfolios->updateOverrides($this->portfolioItem($this->editingPortfolioItemId), [
            'query_text_override' => $this->overrideQueryText,
            'language_code' => $this->overrideLanguage,
            'market_code' => $this->overrideMarket,
            'demand_family_override' => $this->overrideDemandFamily,
            'location_scope_override' => $this->overrideLocationScope,
            'location_value_override' => $this->overrideLocationValue,
            'is_branded_override' => $this->overrideIsBranded,
        ], auth()->user());

        $this->editingPortfolioItemId = null;
        $this->messageTone = 'success';
        $this->message = 'Marka override’ları kaydedildi; global sorgu değişmedi.';
    }

    public function editAreas(int $itemId): void
    {
        $item = $this->portfolioItem($itemId)->load('serviceAreas');
        $this->areaPortfolioItemId = $item->id;
        $this->areaMode = $item->area_scope === 'selected_areas' ? 'selected' : 'all';
        $this->selectedAreaIds = $item->serviceAreas->pluck('id')->all();
    }

    public function cancelAreas(): void
    {
        $this->areaPortfolioItemId = null;
        $this->selectedAreaIds = [];
    }

    public function saveAreas(BrandQueryPortfolioService $portfolios): void
    {
        if ($this->areaPortfolioItemId === null) {
            return;
        }

        $this->validate([
            'areaMode' => ['required', 'in:all,selected'],
            'selectedAreaIds' => [$this->areaMode === 'selected' ? 'required' : 'nullable', 'array'],
            'selectedAreaIds.*' => ['integer', 'exists:brand_service_areas,id'],
        ]);

        $ids = $this->areaMode === 'selected' ? $this->selectedAreaIds : [];
        $portfolios->setAreas($this->portfolioItem($this->areaPortfolioItemId), $ids, auth()->user());
        $this->areaPortfolioItemId = null;
        $this->selectedAreaIds = [];
        $this->messageTone = 'success';
        $this->message = 'Sorgunun bölge kapsamı güncellendi; kalıp varyantları çalışma anında üretilir.';
    }

    public function setPortfolioStatus(int $itemId, string $status, BrandQueryPortfolioService $portfolios): void
    {
        $portfolios->setStatus($this->portfolioItem($itemId), $status, auth()->user());
        $this->messageTone = 'success';
        $this->message = $status === 'active' ? 'Sorgu marka portföyünde etkin.' : 'Sorgu marka portföyünde hariç tutuldu.';
    }

    public function setWebsiteStatus(int $itemId, string $status, BrandQueryPortfolioService $portfolios): void
    {
        if ($this->selectedWebsiteId === '') {
            $this->addError('selectedWebsiteId', 'Önce bir website seçin.');

            return;
        }

        $portfolios->setWebsiteStatus(
            $this->portfolioItem($itemId),
            (int) $this->selectedWebsiteId,
            $status,
            auth()->user(),
        );
        $this->messageTone = 'success';
        $this->message = $status === 'active'
            ? 'Sorgu seçilen website için etkinleştirildi.'
            : 'Sorgu seçilen website için hariç tutuldu.';
    }

    public function proposeToGlobal(int $itemId, BrandQueryPortfolioService $portfolios): void
    {
        $portfolios->proposeToGlobal($this->portfolioItem($itemId), auth()->user());
        $this->messageTone = 'success';
        $this->message = 'Markaya özel sorgu global kütüphane incelemesine önerildi; otomatik kopyalanmadı.';
    }

    public function render(BrandQueryPortfolioService $portfolios): View
    {
        $brands = Brand::query()->with('customer')->orderBy('name')->get();
        $brand = $this->selectedBrandId !== ''
            ? Brand::query()
                ->with([
                    'offerings.catalogItem.primaryName',
                    'serviceAreas' => fn ($query) => $query->where('status', 'active')->orderBy('priority_rank'),
                    'digitalAssets' => fn ($query) => $query->where('type', 'website')->orderBy('name'),
                ])
                ->find((int) $this->selectedBrandId)
            : null;

        $items = collect();
        if ($brand instanceof Brand) {
            $query = BrandQueryPortfolioItem::query()
                ->with([
                    'libraryItem',
                    'services.primaryName',
                    'serviceAreas',
                    'assetStates',
                    'intelligenceIdentity',
                    'brand.serviceAreas',
                ])
                ->where('brand_id', $brand->id);

            if ($this->portfolioStatus !== 'all') {
                $query->where('status', $this->portfolioStatus);
            }
            if (trim($this->search) !== '') {
                $term = '%'.mb_strtolower(trim($this->search), 'UTF-8').'%';
                $query->where(function ($query) use ($term): void {
                    $query->whereRaw("LOWER(COALESCE(custom_canonical_text, '')) LIKE ?", [$term])
                        ->orWhereRaw("LOWER(COALESCE(query_text_override, '')) LIKE ?", [$term])
                        ->orWhereHas('libraryItem', fn ($library) => $library->whereRaw('LOWER(canonical_text) LIKE ?', [$term]));
                });
            }

            $items = $query->latest('id')->limit(300)->get();
            $items->each(function (BrandQueryPortfolioItem $item) use ($portfolios): void {
                $item->setAttribute('rendered_location_variants', $portfolios->locationVariants($item));
            });
        }

        $serviceOptions = $brand instanceof Brand
            ? $brand->offerings
                ->filter(fn ($offering): bool => $offering->status->value === 'active' && $offering->catalogItem !== null)
                ->mapWithKeys(fn ($offering): array => [
                    (string) $offering->catalogItem->id => $offering->catalogItem->primaryName?->raw_label ?? 'İsimsiz hizmet',
                ])
                ->all()
            : [];

        return view('livewire.operator.library.brand-query-portfolio-page', [
            'brands' => $brands,
            'brand' => $brand,
            'items' => $items,
            'serviceOptions' => $serviceOptions,
            'summary' => [
                'total' => $brand?->queryPortfolioItems()->count() ?? 0,
                'active' => $brand?->queryPortfolioItems()->where('status', 'active')->count() ?? 0,
                'custom' => $brand?->queryPortfolioItems()->where('origin_type', 'brand_custom')->count() ?? 0,
                'proposals' => $brand?->queryPortfolioItems()->where('global_proposal_status', 'submitted')->count() ?? 0,
            ],
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail((int) $this->selectedBrandId);
    }

    private function portfolioItem(int $itemId): BrandQueryPortfolioItem
    {
        return BrandQueryPortfolioItem::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->findOrFail($itemId);
    }

    private function ensureWebsiteBelongsToBrand(): void
    {
        if ($this->selectedWebsiteId === '' || $this->selectedBrandId === '') {
            return;
        }

        $valid = Brand::query()
            ->whereKey((int) $this->selectedBrandId)
            ->whereHas('digitalAssets', fn ($query) => $query
                ->whereKey((int) $this->selectedWebsiteId)
                ->where('type', 'website'))
            ->exists();

        if (! $valid) {
            $this->selectedWebsiteId = '';
        }
    }
}
