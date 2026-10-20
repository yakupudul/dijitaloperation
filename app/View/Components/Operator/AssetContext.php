<?php

namespace App\View\Components\Operator;

use App\Models\AssetAlert;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Operator\ExternalLinks;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\DigitalAssetTypes;
use App\Support\Integrations\AssetBindingCompatibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * The one compact page head of every digital asset page, one line before the tabs (yakup 2026-10-20: "too noisy"):
 * brand › asset (the page's h1 when it passes a `title`, with a switcher to the brand's other assets), the `status` slot,
 * then on the right the page's `actions`, the data-status dot (DataStatusReader; details on hover and in its small panel),
 * the open alerts as one chip, a single "Yenile" (only when the page has no refresh button of its own: `refresh`), "Hızlı
 * giriş" and a "⋯" menu holding "Veri kaynakları", "Düzenle" and the brand-website link. The `meta` / `subtitle` line sits
 * under the title, the default slot (banners) under the line, the `tabs` slot last.
 */
class AssetContext extends Component
{
    public ?DigitalAsset $asset;

    /** @var list<array{name: string, type_label: string, url: string, current: bool, tone: string}> */
    public array $siblings = [];

    public string $typeLabel = '';

    /** Breadcrumb: the customer only for an asset without a brand; Müşteriler is merged into Markalar (yakup 2026-10-20). */
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

    /** Turkish asset type names for the head and the switcher ("Website" → "Web sitesi"). */
    public const array TYPE_LABELS = [
        'website' => 'Web sitesi', 'google_business_profile' => 'İşletme Profili', 'gbp' => 'İşletme Profili', 'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads', 'instagram' => 'Instagram', 'ga4' => 'Google Analytics', 'gsc' => 'Google Search Console',
    ];

    /**
     * @param  string|null  $title  h1 of the page; when given the head renders the title row (breadcrumb → title → status → tabs)
     * @param  string|null  $subtitle  one gray line under the title (or pass a `meta` slot)
     * @param  bool  $refresh  show the head's single "Yenile"; false when the page has its own refresh button
     */
    public function __construct(int|string|null $assetId, public string $current = 'page', public ?string $title = null, public ?string $subtitle = null, public bool $refresh = true)
    {
        $this->alerts = collect();
        $this->asset = ctype_digit((string) $assetId)
            ? DigitalAsset::query()->with('brand.customer')->find((int) $assetId)
            : null;
        if ($this->asset === null) {
            return;
        }

        $asset = $this->asset;
        $this->typeLabel = self::typeLabel((string) $asset->type);
        $this->assetLabel = self::assetTitle($asset);
        $customer = $asset->brand?->customer;
        $this->brandLogo = filled($asset->brand?->logo_url) ? (string) $asset->brand->logo_url : null;
        $this->showCustomer = $customer !== null && $asset->brand === null;
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
            'type_label' => self::typeLabel((string) $sibling->type),
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

    /** "website" → "Web sitesi"; an unknown type keeps the catalog's name. */
    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? DigitalAssetTypes::options()[$type] ?? $type;
    }

    /**
     * Turkish head name of an asset: "Web sitesi · moximu.com", "İşletme Profili · Avrupadent Çiğli" (the Google name's
     * "İşletme Profili ·" prefix and "| …" tail dropped), an English type prefix in a stored name replaced.
     */
    public static function assetTitle(DigitalAsset $asset): string
    {
        $type = (string) $asset->type;
        $name = (string) $asset->name;
        $english = DigitalAssetTypes::options()[$type] ?? null;
        if ($english !== null) {
            $name = (string) preg_replace('/^\s*'.preg_quote($english, '/').'\s*[·:\-]\s*/iu', '', $name);
        }

        return self::labelFor(self::typeLabel($type), self::isProfile($type) ? GbpDesk::shortName($name) : $name);
    }

    private static function isProfile(string $type): bool
    {
        return in_array($type, ['google_business_profile', 'gbp'], true);
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
