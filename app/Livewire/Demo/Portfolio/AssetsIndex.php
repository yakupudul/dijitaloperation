<?php

namespace App\Livewire\Demo\Portfolio;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatus;
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
 * lives in the URL, so a brand page can link to `?brand={id}`. Without a brand filter the page opens on the brand ×
 * channel matrix (one row per brand); with one, or after picking a quick view, on the list.
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

    /** Matrix columns: channel key => label. `ga4_gsc` reads the GA4 / Search Console sources of the brand's websites. */
    public const array MATRIX_COLUMNS = ['website' => 'Web', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta', 'google_business_profile' => 'İşletme Profili', 'ga4_gsc' => 'GA4 / GSC'];

    /** Page-local badge tone rank: loss (red, money or traffic lost) > warn (amber, look at it) > ok > muted (grey, info). */
    private const array BADGE_RANK = ['loss' => 4, 'warn' => 3, 'ok' => 2, 'muted' => 1];

    /** Empty = automatic: the matrix without a brand filter, the list with one. */
    #[Url(as: 'view', except: '', history: true)]
    public string $viewMode = '';

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
        // Quick views filter assets, which the brand matrix does not list: show them as a list.
        if ($this->quickView !== 'all' && $this->resolvedView() === 'matrix') {
            $this->viewMode = 'table';
        }
    }

    /** The view actually shown: an explicit choice, else the matrix unless one brand is picked. */
    public function resolvedView(): string
    {
        if (in_array($this->viewMode, ['table', 'matrix', 'cards'], true)) {
            return $this->viewMode;
        }

        return $this->filterBrand !== '' ? 'table' : 'matrix';
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
        $allAssets = $models->map(function (DigitalAsset $asset) use ($runtime, $signals): array {
            $presented = OperatorPortfolioPresenter::asset($asset, $runtime[(int) $asset->id] ?? [], $signals['assets'][(int) $asset->id] ?? $this->noSignal());

            return $presented + ['badge' => self::dataBadge($presented)];
        });

        $assets = $this->filtered($allAssets);

        $typeOptions = DigitalAssetTypes::options();
        if ($showingLegacyInfrastructure) {
            $typeOptions['domain'] = 'Domain (eski)';
            $typeOptions['hosting'] = 'Hosting (eski)';
        }
        $matrixBrands = $brands
            ->when($this->filterBrand !== '', fn (Collection $c) => $c->where('id', (int) $this->filterBrand))
            ->when($this->filterCustomer !== '', fn (Collection $c) => $c->where('customer_id', (int) $this->filterCustomer))
            ->when($this->search !== '', fn (Collection $c) => $c->filter(fn (Brand $brand): bool => str_contains(mb_strtolower($brand->name), mb_strtolower($this->search))))
            ->values();

        return view('livewire.demo.portfolio.assets-index', [
            'assets' => $assets->values()->all(),
            'glance' => OperatorPortfolioPresenter::assetsGlance($allAssets->all()),
            'view' => $this->resolvedView(),
            'matrix' => self::brandMatrix($matrixBrands, $allAssets),
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

    /**
     * One short, non-wrapping data badge for an asset: the worst source's state with its reason ("Durgun · 12 gün
     * harcama yok", "Engelli", "Gecikmiş · 5 gün", "Güncel"); every source with its detail goes into the tooltip.
     *
     * @param  array<string, mixed>  $asset  presented asset with PortfolioSignalsReader sources
     * @return array{label: string, title: string, tone: string}
     */
    public static function dataBadge(array $asset): array
    {
        $sources = collect($asset['sources'] ?? [])->map(fn (array $source): array => $source + ['badge_tone' => self::sourceTone($source)]);
        if ($sources->isEmpty()) {
            return ['label' => 'Kaynak yok', 'title' => 'Bu varlığa bağlı veri kaynağı yok', 'tone' => 'muted'];
        }
        $worst = $sources->sortByDesc(fn (array $s): int => self::BADGE_RANK[$s['badge_tone']] ?? 0)->first();
        $problems = $sources->filter(fn (array $s): bool => in_array($s['badge_tone'], ['loss', 'warn'], true))->count();
        $label = self::shortState($worst).($problems > 1 ? ' +'.($problems - 1) : '');
        $title = $sources->map(fn (array $s): string => $s['label'].': '.$s['state_label'].(($s['detail'] ?? '') !== '' ? ' · '.$s['detail'] : ''))->implode("\n");
        $active = ($asset['operational_status'] ?? 'active') === 'active';

        return ['label' => $label, 'title' => $active ? $title : 'Varlık pasif · '.$title, 'tone' => $active ? $worst['badge_tone'] : 'muted'];
    }

    /**
     * Page-local colour of one source: red only when ads stop delivering (money / traffic lost), amber for something to
     * look at, grey for information, green when current.
     *
     * @param  array<string, mixed>  $source
     */
    private static function sourceTone(array $source): string
    {
        return match ($source['state'] ?? '') {
            DataStatus::FRESH => 'ok',
            DataStatus::NOT_DELIVERING => 'loss',
            DataStatus::ACCOUNT_BLOCKED => ($source['reason'] ?? null) === 'meta_grace_period' ? 'warn' : 'loss',
            DataStatus::STALE, DataStatus::FIRST_LOAD, DataStatus::ACCESS_PROBLEM => 'warn',
            DataStatus::PAUSED => ($source['reason'] ?? null) === DataStatus::REASON_IDLE ? 'warn' : 'muted',
            default => 'muted',
        };
    }

    /** @param  array<string, mixed>  $source */
    private static function shortState(array $source): string
    {
        $age = $source['age_days'] ?? null;

        return match ($source['state'] ?? '') {
            DataStatus::FRESH => 'Güncel',
            DataStatus::ACCESS_PROBLEM => 'Erişim sorunu',
            DataStatus::NOT_DELIVERING => 'Yayın durdu',
            DataStatus::ACCOUNT_BLOCKED => ($source['reason'] ?? null) === 'meta_grace_period' ? 'Ödeme ek süresi' : 'Engelli',
            DataStatus::PAUSED => ($source['reason'] ?? null) === DataStatus::REASON_IDLE
                ? 'Durgun · '.(is_int($age) && $age >= 7 ? $age.' gün ' : '').'harcama yok'
                : 'Pasif',
            DataStatus::NOT_BOUND => 'Bağlı değil',
            default => (string) ($source['state_label'] ?? '—'),
        };
    }

    /**
     * Brand × channel matrix: one row per brand, one cell per channel with the worst badge tone of its accounts and
     * how many there are. A single account opens its page; several open the list filtered to that brand and type.
     *
     * @param  Collection<int, Brand>  $brands
     * @param  Collection<int, array<string, mixed>>  $assets  presented assets with their `badge`
     * @return array{columns: array<string, string>, rows: list<array<string, mixed>>}
     */
    public static function brandMatrix(Collection $brands, Collection $assets): array
    {
        $byBrand = $assets->groupBy('brand_id');
        $rows = $brands->map(function (Brand $brand) use ($byBrand): array {
            $brandAssets = $byBrand->get((string) $brand->id, collect());
            $cells = [];
            foreach (array_keys(self::MATRIX_COLUMNS) as $column) {
                $cells[$column] = $column === 'ga4_gsc'
                    ? self::analyticsCell($brand, $brandAssets->where('type', 'website')->values())
                    : self::channelCell($brand, $column, $brandAssets->filter(fn (array $a): bool => $a['type'] === $column
                        || ($column === 'google_business_profile' && $a['type'] === 'gbp'))->values());
            }

            return ['brand_id' => (string) $brand->id, 'brand' => $brand->name, 'customer' => $brand->customer?->name ?? '—', 'cells' => $cells];
        })->values()->all();

        return ['columns' => self::MATRIX_COLUMNS, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private static function channelCell(Brand $brand, string $type, Collection $assets): array
    {
        if ($assets->isEmpty()) {
            return ['count' => 0];
        }
        $worst = $assets->sortByDesc(fn (array $a): int => self::BADGE_RANK[$a['badge']['tone']] ?? 0)->first();

        return [
            'count' => $assets->count(),
            'tone' => $worst['badge']['tone'],
            'label' => $worst['badge']['label'],
            'title' => $assets->map(fn (array $a): string => $a['display_name'].': '.$a['badge']['label'])->implode("\n"),
            'url' => $assets->count() === 1 ? $assets->first()['url'] : route('operator.assets', ['brand' => $brand->id, 'type' => $type, 'view' => 'table']),
        ];
    }

    /**
     * GA4 and Search Console are sources of the brand's websites: the cell counts the bound ones.
     *
     * @param  Collection<int, array<string, mixed>>  $websites
     * @return array<string, mixed>
     */
    private static function analyticsCell(Brand $brand, Collection $websites): array
    {
        $sources = $websites->flatMap(fn (array $site): array => collect($site['sources'] ?? [])
            ->only(['ga4', 'search_console'])
            ->reject(fn (array $s): bool => ($s['state'] ?? '') === DataStatus::NOT_BOUND)
            ->map(fn (array $s): array => $s + ['site' => $site, 'badge_tone' => self::sourceTone($s)])
            ->values()->all());
        if ($sources->isEmpty()) {
            return ['count' => 0];
        }
        $worst = $sources->sortByDesc(fn (array $s): int => self::BADGE_RANK[$s['badge_tone']] ?? 0)->first();
        $sites = $sources->pluck('site')->unique('id');

        return [
            'count' => $sources->count(),
            'tone' => $worst['badge_tone'],
            'label' => self::shortState($worst),
            'title' => $sources->map(fn (array $s): string => $s['label'].' ('.$s['site']['display_name'].'): '.self::shortState($s))->implode("\n"),
            'url' => $sites->count() === 1 ? $sites->first()['url'] : route('operator.assets', ['brand' => $brand->id, 'type' => 'website', 'view' => 'table']),
        ];
    }

    /** @return array<string, mixed> */
    private function noSignal(): array
    {
        return ['open_work' => 0, 'critical' => 0, 'sources' => [], 'worst_tone' => 'muted', 'data_state' => null, 'data_label' => 'Veri kaynağı yok',
            'data_issues' => 0, 'reconnect' => 0, 'needs_attention' => false, 'reason' => null];
    }
}
