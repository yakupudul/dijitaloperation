<?php

namespace App\Livewire\Demo\Portfolio;

use App\Models\Brand;
use App\Models\Customer;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
use App\Support\Demo\DemoState;
use App\Support\DigitalAssetTypes;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Brands')]
class BrandsIndex extends Component
{
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
        $this->dir = 'asc';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function enrichedBrands(): array
    {
        return Brand::query()
            ->with(['customer', 'responsibleUsers', 'digitalAssets', 'intelligenceContext'])
            ->get()
            ->map(fn (Brand $brand): array => OperatorPortfolioPresenter::brand($brand))
            ->values()
            ->all();
    }

    public function render(): View
    {
        $rows = collect($this->enrichedBrands());

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
            $rows = $rows->filter(fn (array $b): bool => ($b['sector'] ?? '') === $this->sector);
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
        $dir = $this->dir === 'desc' ? 'desc' : 'asc';
        $rows = $rows->sortBy(function (array $b) use ($sort) {
            return match ($sort) {
                'customer' => mb_strtolower((string) ($b['customer_name'] ?? '')),
                'sector' => mb_strtolower((string) ($b['sector_label'] ?? '')),
                'assets' => (int) ($b['assets_count'] ?? 0),
                'findings' => (int) ($b['open_findings'] ?? 0),
                'tasks' => (int) ($b['open_tasks'] ?? 0),
                default => mb_strtolower((string) ($b['name'] ?? '')),
            };
        }, SORT_REGULAR, $dir === 'desc')->values();

        $all = collect($this->enrichedBrands());

        return view('livewire.demo.portfolio.brands-index', [
            'brands' => $rows->all(),
            'allCount' => $all->count(),
            'summaryLine' => sprintf(
                '%d brands · %d digital assets · %d brands need attention',
                $all->count(),
                $all->sum(fn (array $b): int => (int) ($b['assets_count'] ?? 0)),
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
