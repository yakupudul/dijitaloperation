<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Services\Demand\BrandQueryHub;
use App\Support\Permissions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Brand page › İşletme › Sorgu merkezi: every query of the brand (all sources) with its service, sector, intent and
 * relevance; filters (kaynak, hizmet, belirsiz, alakasız, markalı, site), counts per service and bulk review
 * (assign to a service, mark alakasız, confirm). Operator decisions win over the weekly rebuild.
 */
final class BrandQueryHubPanel extends Component
{
    use WithPagination;

    #[Locked]
    public int $brandId;

    public string $source = '';

    public string $offering = '';

    public string $relevance = '';

    public string $branded = '';

    public string $site = '';

    public string $search = '';

    /** @var list<int|string> */
    public array $selected = [];

    public string $bulkOffering = '';

    public string $message = '';

    public function mount(int $brandId): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->brandId = (int) Brand::query()->findOrFail($brandId)->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['source', 'offering', 'relevance', 'branded', 'site', 'search'], true)) {
            $this->resetPage('sorguSayfa');
            $this->selected = [];
        }
    }

    #[On('brand-query-hub-updated')]
    public function refreshHub(): void
    {
        $this->resetPage('sorguSayfa');
    }

    public function showService(string $offering): void
    {
        $this->offering = $offering;
        $this->resetPage('sorguSayfa');
        $this->selected = [];
    }

    public function selectPage(BrandQueryHub $hub): void
    {
        $ids = $this->rows($hub)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->selected = array_slice(array_values(array_unique([...array_map('intval', $this->selected), ...$ids])), 0, 500);
    }

    public function bulkAssign(BrandQueryHub $hub): void
    {
        $ids = $this->selectedIds();
        if ($ids === [] || $this->bulkOffering === '') {
            $this->message = 'Önce sorgu ve hizmet seçin.';

            return;
        }
        $brand = Brand::query()->findOrFail($this->brandId);
        $offering = BrandOffering::query()->where('brand_id', $this->brandId)->findOrFail((int) $this->bulkOffering);
        $count = $hub->assign($brand, $ids, $offering, auth()->user());
        $this->done($count.' sorgu "'.$offering->displayName().'" hizmetine atandı.');
    }

    public function bulkIrrelevant(BrandQueryHub $hub): void
    {
        $ids = $this->selectedIds();
        if ($ids === []) {
            $this->message = 'Önce sorgu seçin.';

            return;
        }
        $count = $hub->markIrrelevant(Brand::query()->findOrFail($this->brandId), $ids, auth()->user());
        $this->done($count.' sorgu alakasız olarak işaretlendi (silinmez; filtreden görülebilir).');
    }

    public function bulkConfirm(BrandQueryHub $hub): void
    {
        $ids = $this->selectedIds();
        if ($ids === []) {
            $this->message = 'Önce sorgu seçin.';

            return;
        }
        $count = $hub->confirm(Brand::query()->findOrFail($this->brandId), $ids, auth()->user());
        $this->done($count.' sorgunun ataması onaylandı; haftalık yenileme değiştirmez.');
    }

    public function render(BrandQueryHub $hub): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $siteModel = $this->siteModel();
        $offerings = BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $this->brandId)->where('status', 'active')->get()
            ->mapWithKeys(fn (BrandOffering $o): array => [$o->id => $o->displayName()])->all();
        $filters = $this->filters();
        $counts = $hub->serviceCounts($brand, $siteModel, $filters);
        $serviceCounts = collect($counts)->map(fn (int $total, int $id): array => [
            'id' => $id === 0 ? 'none' : (string) $id,
            'name' => $id === 0 ? 'Hizmet yok' : ($offerings[$id] ?? 'Hizmet #'.$id),
            'total' => $total,
        ])->sortByDesc('total')->values()->all();

        return view('livewire.operator.portfolio.brand-query-hub-panel', [
            'rows' => $this->rows($hub),
            'offerings' => $offerings,
            'serviceCounts' => $serviceCounts,
            'relevanceCounts' => $hub->relevanceCounts($brand, $siteModel, $filters),
            'sites' => DigitalAsset::query()->where('brand_id', $this->brandId)->where('type', 'website')->orderBy('id')->get(['id', 'domain', 'primary_url']),
            'sourceLabels' => BrandDemandQuery::SOURCE_LABELS,
            'methodLabels' => BrandDemandQuery::METHOD_LABELS,
            'relevanceLabels' => BrandDemandQuery::RELEVANCE_LABELS,
            'sectorNames' => ServiceCategory::query()->pluck('name', 'code')->all(),
        ]);
    }

    /** @return LengthAwarePaginator<int, BrandDemandQuery> */
    private function rows(BrandQueryHub $hub): LengthAwarePaginator
    {
        return $hub->query(Brand::query()->findOrFail($this->brandId), $this->siteModel(), $this->filters())
            ->paginate(50, pageName: 'sorguSayfa');
    }

    /** @return array{source: string, offering: string, relevance: string, branded: string, search: string} */
    private function filters(): array
    {
        return [
            'source' => $this->source, 'offering' => $this->offering, 'relevance' => $this->relevance,
            'branded' => $this->branded, 'search' => $this->search,
        ];
    }

    private function siteModel(): ?DigitalAsset
    {
        return $this->site === '' ? null : DigitalAsset::query()->where('brand_id', $this->brandId)->find((int) $this->site);
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        return array_slice(array_values(array_unique(array_map('intval', $this->selected))), 0, 500);
    }

    private function done(string $message): void
    {
        $this->message = $message;
        $this->selected = [];
        $this->bulkOffering = '';
        $this->dispatch('brand-query-hub-updated');
    }
}
