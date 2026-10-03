<?php

namespace App\Livewire\Demo\Portfolio;

use App\Models\Brand;
use App\Models\Customer;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Operator\PortfolioSignalsReader;
use App\Services\Portfolio\PortfolioDeletionService;
use App\Support\Demo\DemoState;
use App\Support\DigitalAssetTypes;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('operator.layouts.app')]
#[Title('Markalar')]
class BrandsIndex extends Component
{
    use WithPagination;

    public const int PER_PAGE = 50;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $customer = '';

    #[Url(history: true)]
    public string $sector = '';

    #[Url(history: true)]
    public string $primary_market = '';

    #[Url(history: true)]
    public string $asset_type = '';

    #[Url(history: true)]
    public string $responsible = '';

    #[Url(history: true)]
    public string $attention = '';

    #[Url(history: true)]
    public string $context = '';

    #[Url(history: true)]
    public string $sort = 'name';

    #[Url(history: true)]
    public string $dir = 'asc';

    public bool $showOptionalColumns = false;

    /** @var list<int> selected brand ids for bulk actions */
    public array $selected = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'customer', 'sector', 'primary_market', 'asset_type', 'responsible', 'attention', 'context'], true)) {
            $this->resetPage();
        }
    }

    public function toggleAll(array $visibleIds): void
    {
        $visibleIds = array_map('intval', $visibleIds);
        $this->selected = array_values(array_intersect($this->selected, $visibleIds)) === $visibleIds && $visibleIds !== []
            ? []
            : $visibleIds;
    }

    /** Admin-only removal of the selected brands (and their assets): data is kept, collection stops. */
    public function deleteSelected(PortfolioDeletionService $deletion): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        if ($ids === []) {
            return;
        }
        $result = $deletion->deleteBrands($ids, auth()->user());
        $this->selected = [];
        DemoState::flash($result['deleted'].' marka silindi. Toplanan veriler korundu, veri çekimi durdu; hesap tekrar bir markaya bağlanırsa çekim devam eder.'.($result['skipped'] > 0 ? ' '.$result['skipped'].' kayıt silinemedi.' : ''), $result['skipped'] > 0 ? 'warning' : 'success');
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->customer = '';
        $this->sector = '';
        $this->primary_market = '';
        $this->asset_type = '';
        $this->responsible = '';
        $this->attention = '';
        $this->context = '';
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->customer !== ''
            || $this->sector !== ''
            || $this->primary_market !== ''
            || $this->asset_type !== ''
            || $this->responsible !== ''
            || $this->attention !== ''
            || $this->context !== '';
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $column;
        $this->dir = in_array($column, ['work', 'attention'], true) ? 'desc' : 'asc';
    }

    /**
     * Every brand presented once per render, with its signals (open work, data status, attention) from
     * PortfolioSignalsReader in a fixed number of queries.
     *
     * @return list<array<string, mixed>>
     */
    protected function enrichedBrands(): array
    {
        $models = Brand::query()
            ->with(['customer', 'responsibleUsers', 'digitalAssets.assetBindings', 'intelligenceContext', 'sectorCategory'])
            ->get();
        $signals = app(PortfolioSignalsReader::class)->forBrands($models)['brands'];

        return $models
            ->map(fn (Brand $brand): array => OperatorPortfolioPresenter::brand($brand, $signals[(int) $brand->id] ?? PortfolioSignalsReader::emptyBrand()))
            ->values()
            ->all();
    }

    public function render(): View
    {
        $all = collect($this->enrichedBrands());
        $rows = $all;

        if ($this->search !== '') {
            $q = mb_strtolower($this->search);
            $rows = $rows->filter(function (array $brand) use ($q): bool {
                $hay = mb_strtolower(implode(' ', array_filter([
                    $brand['name'] ?? '',
                    $brand['customer_name'] ?? '',
                    $brand['sector_label'] ?? '',
                    $brand['website'] ?? '',
                ])));

                return str_contains($hay, $q);
            });
        }

        if ($this->customer !== '') {
            $rows = $rows->filter(fn (array $b): bool => ($b['customer_id'] ?? '') === $this->customer);
        }
        if ($this->sector !== '') {
            $rows = $rows->filter(fn (array $b): bool => ($b['sector'] ?? '') === $this->sector || in_array($this->sector, $b['sector_codes'] ?? [], true));
        }
        if ($this->primary_market !== '') {
            $rows = $rows->filter(fn (array $b): bool => ($b['primary_country'] ?? '') === $this->primary_market);
        }
        if ($this->asset_type !== '') {
            $rows = $rows->filter(fn (array $b): bool => in_array($this->asset_type, $b['asset_types'] ?? [], true));
        }
        if ($this->responsible !== '') {
            $rows = $rows->filter(fn (array $b): bool => in_array($this->responsible, $b['responsible_user_ids'] ?? [], true));
        }
        if ($this->attention === 'needs_attention') {
            $rows = $rows->filter(fn (array $b): bool => (bool) ($b['needs_attention'] ?? false));
        } elseif ($this->attention === 'clear') {
            $rows = $rows->filter(fn (array $b): bool => ! ($b['needs_attention'] ?? false));
        }
        if ($this->context === 'complete') {
            $rows = $rows->filter(fn (array $b): bool => ($b['context_ratio'] ?? 0) >= 0.75);
        } elseif ($this->context === 'incomplete') {
            $rows = $rows->filter(fn (array $b): bool => ($b['context_ratio'] ?? 0) > 0 && ($b['context_ratio'] ?? 0) < 0.75);
        } elseif ($this->context === 'not_started') {
            $rows = $rows->filter(fn (array $b): bool => ($b['context_completed'] ?? 0) === 0);
        }

        $sort = $this->sort;
        $rows = $rows->sortBy(function (array $b) use ($sort) {
            return match ($sort) {
                'customer' => mb_strtolower((string) ($b['customer_name'] ?? '')),
                'sector' => mb_strtolower((string) ($b['sector_label'] ?? '')),
                'assets' => (int) ($b['assets_count'] ?? 0),
                'work' => (int) ($b['open_work'] ?? 0),
                'attention' => ((int) ($b['needs_attention'] ?? false)) * 1000 + (int) ($b['data_issues'] ?? 0),
                default => mb_strtolower((string) ($b['name'] ?? '')),
            };
        }, SORT_REGULAR, $this->dir === 'desc')->values();

        $page = max(1, $this->getPage());
        $brands = new LengthAwarePaginator($rows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values()->all(), $rows->count(), self::PER_PAGE, $page);

        return view('livewire.demo.portfolio.brands-index', [
            'brands' => $brands,
            'allCount' => $all->count(),
            'visibleIds' => array_map(fn (array $b): int => (int) $b['id'], $brands->items()),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'summaryLine' => sprintf(
                '%d marka · %d dijital varlık (%d bağlı) · %d marka dikkat istiyor',
                $all->count(),
                $all->sum(fn (array $b): int => (int) ($b['assets_count'] ?? 0)),
                $all->sum(fn (array $b): int => (int) ($b['connected_assets'] ?? 0)),
                $all->filter(fn (array $b): bool => (bool) ($b['needs_attention'] ?? false))->count()
            ),
            'hasFilters' => $this->hasActiveFilters(),
            'customerOptions' => Customer::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(
                fn ($name, $id): array => [(string) $id => (string) $name]
            )->all(),
            'sectorOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'assetTypeOptions' => DigitalAssetTypes::options(),
            'teamOptions' => OperatorUserDirectory::options(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
