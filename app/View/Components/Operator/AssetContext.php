<?php

namespace App\View\Components\Operator;

use App\Models\AssetAlert;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\Operator\AssetRuntimeStatusReader;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\DigitalAssetTypes;
use App\Support\Integrations\AssetBindingCompatibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * The shared frame line above every digital asset page: Customer › Brand › Asset breadcrumb, a switcher
 * to the brand's other assets, and one status strip (connected accounts, last data, freshness) with the
 * "Veri Kaynakları" and "Düzenle" links. Channel pages keep their own tabs and actions below it.
 */
class AssetContext extends Component
{
    public ?DigitalAsset $asset;

    /** @var list<array{name: string, type_label: string, url: string, current: bool, data_state: string}> */
    public array $siblings = [];

    /** @var list<array{label: string, resource: string}> */
    public array $accounts = [];

    /** @var array<string, mixed> */
    public array $status = [];

    public string $typeLabel = '';

    public bool $hasSources = false;

    /** Website tab that now holds this GA4 / Search Console data, when the brand's website has it bound. */
    public ?string $websiteHome = null;

    /** @var Collection<int, AssetAlert> */
    public Collection $alerts;

    public function __construct(int|string|null $assetId, public string $current = 'page')
    {
        $this->alerts = collect();
        $this->asset = ctype_digit((string) $assetId)
            ? DigitalAsset::query()->with('brand.customer')->find((int) $assetId)
            : null;
        if ($this->asset === null) {
            return;
        }

        $asset = $this->asset;
        $this->typeLabel = DigitalAssetTypes::options()[(string) $asset->type] ?? (string) $asset->type;
        $this->hasSources = AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type) !== [];

        $brandAssets = DigitalAsset::query()
            ->where('brand_id', $asset->brand_id)
            ->whereNotIn('type', ['domain', 'hosting'])
            ->orderBy('type')
            ->orderBy('name')
            ->get();
        $runtime = app(AssetRuntimeStatusReader::class)->forAssets($brandAssets);
        $this->status = $runtime[(int) $asset->id] ?? [];
        $this->siblings = $brandAssets->map(fn (DigitalAsset $sibling): array => [
            'name' => (string) $sibling->name,
            'type_label' => DigitalAssetTypes::options()[(string) $sibling->type] ?? (string) $sibling->type,
            'url' => OperatorPortfolioPresenter::specialistUrl($sibling),
            'current' => $sibling->is($asset),
            'data_state' => (string) ($runtime[(int) $sibling->id]['data_state'] ?? 'unavailable'),
        ])->values()->all();

        $capability = ['ga4' => 'ga4', 'gsc' => 'search_console'][(string) $asset->type] ?? null;
        $website = $capability !== null ? $brandAssets->firstWhere('type', 'website') : null;
        if ($website !== null && CoreAssetBinding::query()->where('digital_asset_id', $website->id)->where('capability', $capability)->where('status', CoreAssetBinding::STATUS_ACTIVE)->exists()) {
            $this->websiteHome = route('operator.website', ['assetId' => $website->id, 'tab' => $asset->type === 'ga4' ? 'ga4_analysis' : 'search_console']);
        }

        $this->alerts = AssetAlert::query()->open()->where('digital_asset_id', $asset->id)
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->get();

        $this->accounts = CoreAssetBinding::query()
            ->with('externalResource')
            ->where('digital_asset_id', $asset->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->get()
            ->map(fn (CoreAssetBinding $binding): array => [
                'label' => BrandWorkspaceReadService::ACCOUNT_LABELS[$binding->capability] ?? (string) $binding->capability,
                'resource' => (string) ($binding->externalResource?->display_name ?: $binding->externalResource?->external_id ?: '—'),
            ])
            ->values()
            ->all();
    }

    public function shouldRender(): bool
    {
        return $this->asset !== null;
    }

    public function render(): View
    {
        return view('components.operator.asset-context');
    }
}
