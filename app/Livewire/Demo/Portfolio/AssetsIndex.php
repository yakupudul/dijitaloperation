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
    public bool $showWizard = false;

    public int $step = 1;

    public string $assetType = 'website';

    public string $assetName = '';

    public string $connectionMode = 'public';

    public string $filterBrand = '';

    public string $filterType = '';

    public string $filterHealth = '';

    public string $filterRole = '';

    public string $viewMode = 'cards';

    public function openWizard(): void
    {
        $this->showWizard = true;
        $this->step = 1;
        $this->assetType = 'website';
        $this->assetName = '';
        $this->connectionMode = 'public';
    }

    public function closeWizard(): void
    {
        $this->showWizard = false;
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate(['assetType' => 'required|string']);
            $this->step = 2;

            return;
        }

        if ($this->step === 2) {
            $this->validate(['assetName' => 'required|string|min:2|max:120']);
            $this->step = 3;

            return;
        }

        DemoState::flash('Asset “'.$this->assetName.'” added in Demo Mode (session only — not written to operator DB).');
        $this->closeWizard();
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

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
        $allAssets = DemoCatalog::assets();
        $assets = collect($allAssets);

        if ($this->filterBrand !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['brand_id'] ?? '') === $this->filterBrand);
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

        $wizardTypes = [
            'website' => 'Website',
            'meta_ads' => 'Meta Ads',
            'google_ads' => 'Google Ads',
            'gbp' => 'Google Business Profile',
            'ga4' => 'Google Analytics',
            'gsc' => 'Search Console',
        ];

        return view('livewire.demo.portfolio.assets-index', [
            'assets' => $assets->values()->all(),
            'brandOptions' => [
                DemoCatalog::BRAND_ID => $brand['name'],
            ],
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
            'wizardTypes' => $wizardTypes,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
