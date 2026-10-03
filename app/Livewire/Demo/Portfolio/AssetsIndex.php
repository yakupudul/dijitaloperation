<?php

namespace App\Livewire\Demo\Portfolio;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Operator\AssetRuntimeStatusReader;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Operator\PortfolioSignalsReader;
use App\Support\Demo\DemoState;
use App\Support\DigitalAssetTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dijital varlıklar (sidebar): every brand-assigned asset with its data status per source (the worst source wins, as on
 * the brand Özet asset card), its open work and why it needs attention; plus the brand × channel matrix. Every filter
 * lives in the URL, so a brand page can link to `?brand={id}`.
 */
#[Layout('operator.layouts.app')]
#[Title('Dijital varlıklar')]
class AssetsIndex extends Component
{
    public const array QUICK_VIEWS = ['all', 'needs_attention', 'data_issues', 'active_work', 'recent'];

    /** Data filter (source tone) => label. */
    public const array DATA_OPTIONS = ['ok' => 'Güncel', 'warn' => 'Gecikmiş / ilk veri bekleniyor', 'bad' => 'Erişim sorunu', 'muted' => 'Bağlı değil'];

    #[Url(as: 'brand', except: '', history: true)]
    public string $filterBrand = '';

    #[Url(as: 'customer', except: '', history: true)]
    public string $filterCustomer = '';

    #[Url(as: 'type', except: '')]
    public string $filterType = '';

    #[Url(as: 'status', except: '', history: true)]
    public string $filterOperational = '';

    #[Url(as: 'data', except: '', history: true)]
    public string $filterDataState = '';

    #[Url(as: 'attention', except: '', history: true)]
    public string $filterAttention = '';

    #[Url(as: 'owner', except: '', history: true)]
    public string $filterResponsible = '';

    public string $filterHealth = '';

    #[Url(as: 'role', except: '')]
    public string $filterRole = '';

    #[Url(as: 'quick', except: 'all', history: true)]
    public string $quickView = 'all';

    #[Url(as: 'q', except: '', history: true)]
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
        $this->quickView = in_array($view, self::QUICK_VIEWS, true) ? $view : 'all';
    }

    public function render(): View
    {
        $legacyInfrastructureTypes = ['domain', 'hosting'];
        $showingLegacyInfrastructure = in_array($this->filterType, $legacyInfrastructureTypes, true)
            || $this->filterRole === 'infrastructure';

        // Websites without a brand yet are managed under Integrations › Website; every listed asset belongs to a brand.
        $brands = Brand::query()
            ->with(['customer', 'responsibleUsers', 'digitalAssets.assetBindings', 'digitalAssets.findings' => fn ($q) => $q->where('status', 'open')])
            ->orderBy('name')
            ->get();
        $models = $brands->flatMap(function (Brand $brand) use ($showingLegacyInfrastructure, $legacyInfrastructureTypes): Collection {
            return $brand->digitalAssets
                ->reject(fn (DigitalAsset $asset): bool => ! $showingLegacyInfrastructure && in_array($asset->type, $legacyInfrastructureTypes, true))
                ->each(fn (DigitalAsset $asset) => $asset->setRelation('brand', $brand));
        })->values();

        $signals = app(PortfolioSignalsReader::class)->forBrands($brands);
        $runtime = app(AssetRuntimeStatusReader::class)->forAssets($models);
        $allAssets = $models->map(fn (DigitalAsset $asset): array => OperatorPortfolioPresenter::asset(
            $asset, $runtime[(int) $asset->id] ?? [], $signals['assets'][(int) $asset->id] ?? $this->noSignal()));

        $assets = $this->filtered($allAssets);

        $typeOptions = DigitalAssetTypes::options();
        if ($showingLegacyInfrastructure) {
            $typeOptions['domain'] = 'Domain (eski)';
            $typeOptions['hosting'] = 'Hosting (eski)';
        }
        $matrixBrands = $brands
            ->when($this->filterBrand !== '', fn (Collection $c) => $c->where('id', (int) $this->filterBrand))
            ->when($this->filterCustomer !== '', fn (Collection $c) => $c->where('customer_id', (int) $this->filterCustomer))
            ->values();

        return view('livewire.demo.portfolio.assets-index', [
            'assets' => $assets->values()->all(),
            'glance' => OperatorPortfolioPresenter::assetsGlance($allAssets->all()),
            'matrix' => OperatorPortfolioPresenter::estateMatrix($matrixBrands, $signals['assets']),
            'brandOptions' => $brands->mapWithKeys(fn (Brand $brand): array => [(string) $brand->id => $brand->name])->all(),
            'customerOptions' => Customer::query()->orderBy('name')->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
                ->all(),
            'typeOptions' => $typeOptions,
            'responsibleOptions' => OperatorUserDirectory::options(),
            'operationalOptions' => OperatorPortfolioPresenter::OPERATIONAL_LABELS,
            'dataStateOptions' => self::DATA_OPTIONS,
            'showingLegacyInfrastructure' => $showingLegacyInfrastructure,
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $assets
     * @return Collection<int, array<string, mixed>>
     */
    private function filtered(Collection $assets): Collection
    {
        if ($this->filterBrand !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['brand_id'] ?? '') === $this->filterBrand);
        }
        if ($this->filterCustomer !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['customer_id'] ?? '') === $this->filterCustomer);
        }
        if ($this->filterType !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['type'] ?? '') === $this->filterType);
        }
        if ($this->filterOperational !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['operational_status'] ?? '') === $this->filterOperational);
        }
        if ($this->filterDataState !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['tone'] ?? 'muted') === $this->filterDataState);
        }
        if ($this->filterRole !== '') {
            $assets = $assets->filter(fn (array $asset): bool => ($asset['role'] ?? '') === $this->filterRole);
        }
        if ($this->filterResponsible !== '') {
            $assets = $assets->filter(fn (array $asset): bool => in_array($this->filterResponsible, $asset['responsible_user_ids'] ?? [], true));
        }
        if ($this->filterAttention === 'has' || $this->filterHealth === 'needs_attention') {
            $assets = $assets->filter(fn (array $asset): bool => (bool) ($asset['needs_attention'] ?? false));
        }
        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $assets = $assets->filter(fn (array $asset): bool => str_contains(mb_strtolower(implode(' ', [
                $asset['name'] ?? '', $asset['display_name'] ?? '', $asset['type_label'] ?? '', $asset['brand_name'] ?? '', $asset['domain'] ?? '',
            ])), $needle));
        }

        return match ($this->quickView) {
            'needs_attention' => $assets->filter(fn (array $a): bool => (bool) ($a['needs_attention'] ?? false)),
            'data_issues' => $assets->filter(fn (array $a): bool => ((int) ($a['data_issues'] ?? 0)) > 0),
            'active_work' => $assets->filter(fn (array $a): bool => ((int) ($a['open_work'] ?? 0)) > 0),
            'recent' => $assets->sortByDesc(fn (array $a): string => (string) ($a['last_meaningful_activity'] ?? ''))->take(8),
            default => $assets,
        };
    }

    /** @return array<string, mixed> */
    private function noSignal(): array
    {
        return ['open_work' => 0, 'critical' => 0, 'sources' => [], 'worst_tone' => 'muted', 'data_state' => null, 'data_label' => 'Veri kaynağı yok',
            'data_issues' => 0, 'reconnect' => 0, 'needs_attention' => false, 'reason' => null];
    }
}
