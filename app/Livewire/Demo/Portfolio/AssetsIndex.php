<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Digital Assets')]
class AssetsIndex extends Component
{
    public string $filterBrand = '';

    public string $filterCustomer = '';

    public string $filterType = '';

    public string $filterOperational = '';

    public string $filterDataState = '';

    public string $filterAttention = '';

    public string $filterResponsible = '';

    public string $filterHealth = '';

    public string $filterRole = '';

    public string $quickView = 'all';

    public string $search = '';

    #[Url(as: 'view', history: true)]
    public string $viewMode = 'table';

    public function clearFilters(): void
    {
        $this->filterBrand = '';
        $this->filterCustomer = '';
        $this->filterType = '';
        $this->filterOperational = '';
        $this->filterDataState = '';
        $this->filterAttention = '';
        $this->filterResponsible = '';
        $this->filterHealth = '';
        $this->filterRole = '';
        $this->quickView = 'all';
        $this->search = '';
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['table', 'matrix', 'cards'], true) ? $mode : 'table';
    }

    public function setQuickView(string $view): void
    {
        $this->quickView = in_array($view, ['all', 'needs_attention', 'data_issues', 'active_work', 'recent'], true)
            ? $view
            : 'all';
    }

    public function render(): View
    {
        $brand = DemoCatalog::brand();
        $customer = DemoCatalog::customer();
        $allAssets = array_map(
            [GlobalOperatingFixtures::class, 'enrichAsset'],
            array_merge(DemoCatalog::assets(), DemoState::all()['demo_assets'] ?? []),
        );

        // Domain/Hosting are Website Infrastructure — hidden from normal Digital Asset inventory.
        // Legacy records remain reachable via explicit type/role filter (not destructively deleted).
        $legacyInfrastructureTypes = ['domain', 'hosting'];
        $showingLegacyInfrastructure = in_array($this->filterType, $legacyInfrastructureTypes, true)
            || $this->filterRole === 'infrastructure';

        $assets = collect($allAssets);
        if (! $showingLegacyInfrastructure) {
            $assets = $assets->reject(fn (array $asset): bool => in_array($asset['type'] ?? '', $legacyInfrastructureTypes, true));
        }

        if ($this->filterBrand !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['brand_id'] ?? DemoCatalog::BRAND_ID) === $this->filterBrand);
        }

        if ($this->filterCustomer !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['customer_id'] ?? DemoCatalog::CUSTOMER_ID) === $this->filterCustomer);
        }

        if ($this->filterType !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['type'] ?? '') === $this->filterType);
        }

        if ($this->filterOperational !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['operational_status'] ?? '') === $this->filterOperational);
        }

        if ($this->filterDataState !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['data_state'] ?? '') === $this->filterDataState);
        }

        if ($this->filterHealth !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['health'] ?? '') === $this->filterHealth);
        }

        if ($this->filterRole !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['role'] ?? '') === $this->filterRole);
        }

        if ($this->filterResponsible !== '') {
            $assets = $assets->filter(fn (array $asset): bool => in_array($this->filterResponsible, $asset['responsible_user_ids'] ?? [], true));
        }

        if ($this->filterAttention === 'has') {
            $assets = $assets->filter(fn (array $asset): bool => (int) ($asset['open_findings'] ?? 0) > 0 || in_array($asset['health'] ?? '', ['needs_attention', 'warning'], true));
        }

        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $assets = $assets->filter(function (array $asset) use ($needle): bool {
                return str_contains(mb_strtolower((string) ($asset['name'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($asset['type_label'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($asset['brand_name'] ?? '')), $needle);
            });
        }

        $assets = match ($this->quickView) {
            'needs_attention' => $assets->filter(fn (array $a): bool => in_array($a['health'] ?? '', ['needs_attention', 'warning'], true)),
            'data_issues' => $assets->filter(fn (array $a): bool => in_array($a['data_state'] ?? '', ['stale', 'unavailable'], true)),
            'active_work' => $assets->filter(fn (array $a): bool => ((int) ($a['open_tasks'] ?? 0)) > 0),
            'recent' => $assets->sortByDesc(fn (array $a): string => (string) ($a['last_meaningful_activity'] ?? ''))->take(8),
            default => $assets,
        };

        $typeOptions = collect($allAssets)
            ->reject(fn (array $asset): bool => in_array($asset['type'] ?? '', $legacyInfrastructureTypes, true))
            ->mapWithKeys(fn (array $asset): array => [($asset['type'] ?? '') => ($asset['type_label'] ?? '')])
            ->filter()
            ->unique()
            ->all();

        if ($showingLegacyInfrastructure) {
            $typeOptions['domain'] = 'Domain (legacy)';
            $typeOptions['hosting'] = 'Hosting (legacy)';
        }

        $responsibleOptions = collect(DemoCatalog::teamMembers())
            ->mapWithKeys(fn (array $u): array => [$u['id'] => $u['name']])
            ->all();

        return view('livewire.demo.portfolio.assets-index', [
            'assets' => $assets->values()->all(),
            'glance' => GlobalOperatingFixtures::assetsGlance($allAssets),
            'matrix' => GlobalOperatingFixtures::estateMatrix(),
            'brandOptions' => [DemoCatalog::BRAND_ID => $brand['name']],
            'customerOptions' => [DemoCatalog::CUSTOMER_ID => $customer['name']],
            'typeOptions' => $typeOptions,
            'responsibleOptions' => $responsibleOptions,
            'operationalOptions' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'archived' => 'Archived',
                'setup' => 'Setup',
            ],
            'dataStateOptions' => [
                'fresh' => 'Fresh',
                'stale' => 'Stale',
                'unavailable' => 'Unavailable',
                'not_applicable' => 'Not applicable',
            ],
            'healthOptions' => [
                'healthy' => 'Healthy',
                'needs_attention' => 'Needs attention',
                'warning' => 'Warning / renewal',
            ],
            'roleOptions' => [
                'primary_managed' => 'Primary managed asset',
                'connected_source' => 'Connected data source',
                'infrastructure' => 'Legacy infrastructure (deprecated)',
            ],
            'showingLegacyInfrastructure' => $showingLegacyInfrastructure,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
