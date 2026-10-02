<?php

namespace App\View\Components\Operator;

use App\Models\AssetAlert;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\DigitalAssetTypes;
use App\Support\Integrations\AssetBindingCompatibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * The shared frame line above every digital asset page: Customer › Brand › Asset breadcrumb, a switcher
 * to the brand's other assets, the "Veri Kaynakları" and "Düzenle" links, and below it the one "Veri durumu" strip
 * (DataStatusReader) — the only place an asset page says whether its sources are connected and current.
 */
class AssetContext extends Component
{
    public ?DigitalAsset $asset;

    /** @var list<array{name: string, type_label: string, url: string, current: bool, tone: string}> */
    public array $siblings = [];

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
        $statuses = app(DataStatusReader::class)->forAssets($brandAssets);
        $this->siblings = $brandAssets->map(fn (DigitalAsset $sibling): array => [
            'name' => (string) $sibling->name,
            'type_label' => DigitalAssetTypes::options()[(string) $sibling->type] ?? (string) $sibling->type,
            'url' => OperatorPortfolioPresenter::specialistUrl($sibling),
            'current' => $sibling->is($asset),
            'tone' => self::worstTone($statuses[(int) $sibling->id] ?? []),
        ])->values()->all();

        $capability = ['ga4' => 'ga4', 'gsc' => 'search_console'][(string) $asset->type] ?? null;
        $website = $capability !== null ? $brandAssets->firstWhere('type', 'website') : null;
        if ($website !== null && CoreAssetBinding::query()->where('digital_asset_id', $website->id)->where('capability', $capability)->where('status', CoreAssetBinding::STATUS_ACTIVE)->exists()) {
            $this->websiteHome = route('operator.website', ['assetId' => $website->id, 'tab' => $asset->type === 'ga4' ? 'ga4_analysis' : 'search_console']);
        }

        $this->alerts = AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->whereNotIn('kind', DataStatusReader::FRESHNESS_ALERT_KINDS)
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->get();
    }

    /**
     * Switcher dot of a sibling asset: the worst tone of its sources (no sources → muted).
     *
     * @param  list<DataStatus>  $statuses
     */
    private static function worstTone(array $statuses): string
    {
        $tones = array_map(fn (DataStatus $status): string => $status->tone(), $statuses);
        foreach (['bad', 'warn', 'ok'] as $tone) {
            if (in_array($tone, $tones, true)) {
                return $tone;
            }
        }

        return 'muted';
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
