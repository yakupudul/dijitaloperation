<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Digital Assets')]
class AssetsIndex extends Component
{
    public string $filterBrand = '';

    public string $filterType = '';

    public string $filterHealth = '';

    public string $filterRole = '';

    public string $viewMode = 'cards';

    public function clearFilters(): void
    {
        $this->filterBrand = '';
        $this->filterType = '';
        $this->filterHealth = '';
        $this->filterRole = '';
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['cards', 'table'], true) ? $mode : 'cards';
    }

    public function render(): View
    {
        $brand = DemoCatalog::brand();
        $allAssets = array_merge(DemoCatalog::assets(), DemoState::all()['demo_assets'] ?? []);
        $assets = collect($allAssets);

        if ($this->filterBrand !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['brand_id'] ?? DemoCatalog::BRAND_ID) === $this->filterBrand);
        }

        if ($this->filterType !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['type'] ?? '') === $this->filterType);
        }

        if ($this->filterHealth !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['health'] ?? '') === $this->filterHealth);
        }

        if ($this->filterRole !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['role'] ?? '') === $this->filterRole);
        }

        $typeOptions = collect($allAssets)
            ->mapWithKeys(fn (array $asset): array => [($asset['type'] ?? '') => ($asset['type_label'] ?? '')])
            ->filter()
            ->unique()
            ->all();

        $brandOptions = collect(DemoState::all()['brands'] ?? [])
            ->mapWithKeys(fn (array $b): array => [($b['id'] ?? '') => ($b['name'] ?? '')])
            ->all();
        if ($brandOptions === []) {
            $brandOptions = [DemoCatalog::BRAND_ID => $brand['name']];
        }

        return view('livewire.demo.portfolio.assets-index', [
            'assets' => $assets->values()->all(),
            'brandOptions' => $brandOptions,
            'typeOptions' => $typeOptions,
            'healthOptions' => [
                'healthy' => 'Healthy',
                'needs_attention' => 'Needs attention',
                'warning' => 'Warning / renewal',
            ],
            'roleOptions' => [
                'primary_managed' => 'Primary managed asset',
                'connected_source' => 'Connected data source',
                'infrastructure' => 'Infrastructure / lifecycle',
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
