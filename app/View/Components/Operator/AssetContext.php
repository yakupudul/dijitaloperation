<?php

namespace App\View\Components\Operator;

use App\Models\AssetAlert;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Operator\ExternalLinks;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\DigitalAssetTypes;
use App\Support\Integrations\AssetBindingCompatibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * The one compact page head of every digital asset page: a small gray Customer › Brand › Asset breadcrumb (the brand opens
 * its analysis tab) with a switcher to the brand's other assets and the "Veri Kaynakları" / "Düzenle" links, then — when the
 * page passes a `title` — the title row (brand avatar or `mark` slot, h1, `status` slot, `actions` slot on the right, `subtitle`
 * or `meta` slot under it), then the one-line "Veri durumu" (DataStatusReader; the only place an asset page says whether its
 * sources are connected and current), the open alerts as one collapsible line, the default slot and finally the `tabs` slot.
 * Without a title the head is breadcrumb + status line + alerts and the page renders its own title below (older callers).
 */
class AssetContext extends Component
{
    public ?DigitalAsset $asset;

    /** @var list<array{name: string, type_label: string, url: string, current: bool, tone: string}> */
    public array $siblings = [];

    public string $typeLabel = '';

    /** Breadcrumb: the customer is left out when it is named like its brand ("Avrupadent › Avrupadent"). */
    public bool $showCustomer = false;

    /** Breadcrumb: "Meta Ads · Obezite", without repeating the type when the asset name already starts with it. */
    public string $assetLabel = '';

    public bool $hasSources = false;

    /** Website tab that now holds this GA4 / Search Console data, when the brand's website has it bound. */
    public ?string $websiteHome = null;

    /** @var Collection<int, AssetAlert> */
    public Collection $alerts;

    /** @var list<array<string, mixed>> "Hızlı giriş": this asset's own accounts (App\Services\Operator\ExternalLinks) */
    public array $externalLinks = [];

    /** Logo of the brand for the title row avatar. */
    public ?string $brandLogo = null;

    /**
     * @param  string|null  $title  h1 of the page; when given the head renders the title row (breadcrumb → title → status → tabs)
     * @param  string|null  $subtitle  one gray line under the title (or pass a `meta` slot)
     */
    public function __construct(int|string|null $assetId, public string $current = 'page', public ?string $title = null, public ?string $subtitle = null)
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
        $this->assetLabel = self::labelFor($this->typeLabel, (string) $asset->name);
        $customer = $asset->brand?->customer;
        $this->brandLogo = filled($asset->brand?->logo_url) ? (string) $asset->brand->logo_url : null;
        $this->showCustomer = $customer !== null && ($asset->brand === null || ! self::namesMatch((string) $customer->name, (string) $asset->brand->name));
        $this->hasSources = AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type) !== [];
        $this->externalLinks = app(ExternalLinks::class)->forAsset($asset);

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

    /** "Meta Ads" + "Meta Ads · Obezite" → "Meta Ads · Obezite"; "Web sitesi" + "Örnek Site" → "Web sitesi · Örnek Site". */
    public static function labelFor(string $typeLabel, string $name): string
    {
        $name = trim($name);
        $typeLabel = trim($typeLabel);
        if ($typeLabel === '' || $name === '') {
            return $name !== '' ? $name : $typeLabel;
        }

        return str_starts_with(mb_strtolower($name), mb_strtolower($typeLabel)) ? $name : $typeLabel.' · '.$name;
    }

    /** Same name, ignoring case and surrounding spaces. */
    public static function namesMatch(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
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
