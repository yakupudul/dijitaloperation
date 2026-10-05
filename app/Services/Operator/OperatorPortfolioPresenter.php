<?php

namespace App\Services\Operator;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DigitalAsset;
use App\Support\DigitalAssetTypes;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use Illuminate\Support\Collection;

/**
 * Maps canonical Eloquent portfolio records to the existing operator view arrays.
 * Never injects DemoCatalog / Atlas fallbacks. List signals (open work, data status, attention) are computed by
 * PortfolioSignalsReader once per render and merged here; the presenter itself runs no per-row count queries.
 */
final class OperatorPortfolioPresenter
{
    /** How an account of each type is named when its stored name is only a number (displayName). */
    private const array ACCOUNT_NOUNS = [
        'meta_ads' => 'Meta reklam hesabı',
        'google_ads' => 'Google Ads hesabı',
        'google_business_profile' => 'İşletme Profili',
        'gbp' => 'İşletme Profili',
        'ga4' => 'Google Analytics mülkü',
        'gsc' => 'Search Console mülkü',
        'website' => 'Web sitesi',
    ];

    /** Operational state of a digital asset => label. */
    public const array OPERATIONAL_LABELS = ['active' => 'Aktif', 'inactive' => 'Pasif', 'archived' => 'Arşivlendi'];

    /**
     * The customer row. List signals come from PortfolioSignalsReader::forCustomers() and are merged when given; the
     * sector is read from the customer's brands (the customer's own `industry` is only the fallback for a customer
     * without a sectored brand).
     *
     * @param  array<string, mixed>  $signal
     * @return array<string, mixed>
     */
    public static function customer(Customer $customer, array $signal = []): array
    {
        $customer->loadMissing(['brands.digitalAssets', 'brands.sectorCategory', 'responsibleUsers']);

        $typeValue = $customer->type instanceof CustomerType ? $customer->type->value : (string) $customer->type;
        $statusValue = $customer->status instanceof CustomerStatus ? $customer->status->value : (string) $customer->status;
        $responsibleIds = $customer->responsibleUsers->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();

        $industry = $customer->industry;
        $industryLabel = self::industryLabel($industry);
        $brandSectors = $customer->brands
            ->map(fn (Brand $brand): ?array => $brand->sectorCategory !== null ? [(string) $brand->sectorCategory->code, (string) $brand->sectorCategory->name] : null)
            ->filter()
            ->unique(0)
            ->values();
        $sectorCodes = $brandSectors->isNotEmpty() ? $brandSectors->pluck(0)->all() : array_values(array_filter([$industry]));
        $sectorLabel = $brandSectors->isNotEmpty() ? $brandSectors->pluck(1)->implode(', ') : $industryLabel;

        return [
            'id' => (string) $customer->id,
            'name' => $customer->name,
            'legal_name' => $customer->legal_name,
            'type' => $typeValue,
            'type_label' => $typeValue === 'individual' ? 'Bireysel' : 'Şirket',
            'status' => $statusValue,
            'status_label' => __('operator.states.'.$statusValue),
            'industry' => $industry,
            'industry_label' => $industryLabel,
            'sector_codes' => $sectorCodes,
            'sector_label' => $sectorLabel,
            'sector_from_brands' => $brandSectors->isNotEmpty(),
            'hq_country' => $customer->hq_country,
            'hq_city' => $customer->hq_city,
            'hq' => $customer->hqDisplay(),
            'hq_display' => $customer->hqDisplay(),
            'services' => is_array($customer->services) ? array_values($customer->services) : [],
            'service_started_at' => $customer->service_started_at?->toDateString(),
            'primary_email' => $customer->primary_email,
            'primary_phone' => $customer->primary_phone,
            'responsible_user_ids' => $responsibleIds,
            'responsible_labels' => $customer->responsibleUsers->pluck('name')->values()->all(),
            'brands_count' => $customer->brands->count(),
            'digital_assets_count' => $customer->brands->sum(fn (Brand $brand): int => $brand->digitalAssets->whereNotIn('type', ['domain', 'hosting'])->count()),
            'open_work' => (int) ($signal['open_work'] ?? 0),
            'critical' => (int) ($signal['critical'] ?? 0),
            'data_issues' => (int) ($signal['data_issues'] ?? 0),
            'reconnect' => (int) ($signal['reconnect'] ?? 0),
            'needs_attention' => (bool) ($signal['needs_attention'] ?? false),
            'attention_reason' => $signal['reason'] ?? null,
            'updated_at' => $customer->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * The brand row. List signals come from PortfolioSignalsReader::forBrands()['brands'] and are merged when given.
     *
     * @param  array<string, mixed>  $signal
     * @return array<string, mixed>
     */
    public static function brand(Brand $brand, array $signal = []): array
    {
        $brand->loadMissing(['customer', 'responsibleUsers', 'digitalAssets.assetBindings', 'intelligenceContext', 'sectorCategory']);

        $assets = $brand->digitalAssets->reject(fn (DigitalAsset $asset): bool => in_array($asset->type, ['domain', 'hosting'], true));
        $responsibleIds = $brand->responsibleUsers->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
        $context = $brand->intelligenceContext;
        $completed = 0;
        $total = 8;
        if ($context !== null) {
            foreach (['business_summary', 'business_model', 'positioning'] as $field) {
                if (filled($context->{$field})) {
                    $completed++;
                }
            }
            foreach (['products_services', 'priority_offerings', 'target_audiences', 'business_goals', 'conversion_goals'] as $field) {
                if (is_array($context->{$field}) && $context->{$field} !== []) {
                    $completed++;
                }
            }
            $completed = min($total, $completed);
        }

        $connectedAssets = $assets->filter(fn (DigitalAsset $asset): bool => $asset->assetBindings
            ->contains(fn (CoreAssetBinding $binding): bool => $binding->status === CoreAssetBinding::STATUS_ACTIVE))->count();
        $website = $assets->where('type', 'website')->sortBy('id')->first();
        $sectorCode = $brand->sectorCategory?->code;

        return [
            'id' => (string) $brand->id,
            'customer_id' => (string) $brand->customer_id,
            'customer_name' => $brand->customer?->name ?? '—',
            'customer_active' => $brand->customer?->status === CustomerStatus::Active,
            'name' => $brand->name,
            'sector' => $brand->sector,
            'industry' => $brand->sector,
            'sector_codes' => $sectorCode !== null && $sectorCode !== '' ? [(string) $sectorCode] : [],
            'sector_label' => $brand->sectorCategory?->name ?? self::industryLabel($brand->sector),
            'primary_country' => $brand->primary_country,
            'primary_market_label' => CountryOptions::label($brand->primary_country),
            'target_markets' => is_array($brand->target_markets) ? array_values($brand->target_markets) : [],
            'languages' => is_array($brand->languages) ? array_values($brand->languages) : [],
            'description' => $brand->description,
            'audience' => $brand->audience,
            'offerings' => $brand->offerings,
            'competitors' => $brand->competitors,
            'logo_url' => $brand->logo_url,
            'website' => $website !== null ? self::domainOf($website) : null,
            'responsible_user_ids' => $responsibleIds,
            'responsible' => $brand->responsibleUsers->map(static fn ($user): array => self::person($user))->values()->all(),
            'location' => CountryOptions::label($brand->primary_country),
            'assets_count' => $assets->count(),
            'connected_assets' => $connectedAssets,
            'context_completed' => $completed,
            'context_total' => $total,
            'context_ratio' => $total > 0 ? $completed / $total : 0,
            'asset_types' => $assets->pluck('type')->unique()->values()->all(),
            'open_work' => (int) ($signal['open_work'] ?? 0),
            'critical' => (int) ($signal['critical'] ?? 0),
            'open_by_channel' => $signal['open_by_channel'] ?? [],
            'data_issues' => (int) ($signal['data_issues'] ?? 0),
            'reconnect' => (int) ($signal['reconnect'] ?? 0),
            'channels' => $signal['channels'] ?? [],
            'needs_attention' => (bool) ($signal['needs_attention'] ?? false),
            'attention_reason' => $signal['reason'] ?? null,
            'initials' => collect(explode(' ', (string) $brand->name))
                ->map(static fn (string $part): string => mb_substr($part, 0, 1))
                ->take(2)
                ->implode(''),
            'extra_markets' => max(0, count(is_array($brand->target_markets) ? $brand->target_markets : []) - 1),
        ];
    }

    /**
     * A person chip: id, name, e-mail and initials.
     *
     * @return array{id: string, name: string, email: ?string, initials: string}
     */
    public static function person(mixed $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => $user->email,
            'initials' => collect(explode(' ', (string) $user->name))
                ->filter()
                ->map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                ->take(2)
                ->implode(''),
        ];
    }

    /** Sector label of a stored code; the catalog is read once per application instance, not once per row. */
    private static function industryLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }
        if (! app()->bound('operator.portfolio.industry_labels')) {
            app()->instance('operator.portfolio.industry_labels', IndustryOptions::options());
        }

        return app('operator.portfolio.industry_labels')[$code] ?? $code;
    }

    /** The website's host without "www.", from its domain or its primary URL. */
    public static function domainOf(DigitalAsset $asset): ?string
    {
        $domain = $asset->domain ?: (is_string($asset->primary_url) ? parse_url($asset->primary_url, PHP_URL_HOST) : null);

        return is_string($domain) && $domain !== '' ? (string) preg_replace('/^www\./', '', $domain) : null;
    }

    /** True when the stored name is empty or only a provider account number ("2143683742659017", "act_1", "123-456-7890"). */
    public static function isNumericName(?string $name): bool
    {
        $name = trim((string) $name);

        return $name === '' || preg_match('/^(act_)?[\d\s\-]+$/i', $name) === 1;
    }

    /**
     * What the operator reads as the asset's name. An account stored under its bare number or with no name reads as
     * "Marka · Meta reklam hesabı …9017"; the stored name is not changed.
     */
    public static function displayName(DigitalAsset $asset): string
    {
        $name = trim((string) $asset->name);
        if (! self::isNumericName($name)) {
            return $name;
        }
        $digits = (string) preg_replace('/\D/', '', $name);
        $noun = self::ACCOUNT_NOUNS[(string) $asset->type] ?? (BrandOverviewReader::TYPE_LABELS[(string) $asset->type] ?? 'Hesap');
        $brand = $asset->brand?->name;

        return ($brand !== null ? $brand.' · ' : '').$noun.($digits !== '' ? ' …'.substr($digits, -4) : '');
    }

    /** The asset type as the asset lists name it ("Google Business Profile", "Search Console", …). */
    public static function typeLabel(string $type): string
    {
        return DigitalAssetTypes::options()[$type] ?? match ($type) {
            'ga4', 'analytics', 'google_analytics' => 'Google Analytics',
            'gsc', 'search_console' => 'Search Console',
            'gbp', 'google_business_profile' => 'Google Business Profile',
            default => $type,
        };
    }

    /**
     * Connection state comes only from confirmed account bindings: an asset created without one is
     * "defined", never Connected / Configured / Fresh.
     *
     * @param  array{connected?: bool, data_state?: string, data_state_label?: string, last_update?: string, open_tasks?: int, last_sync?: mixed}  $runtime  real status from AssetRuntimeStatusReader (lists)
     * @param  array<string, mixed>  $signal  PortfolioSignalsReader::forBrands()['assets'] row (lists)
     * @return array<string, mixed>
     */
    public static function asset(DigitalAsset $asset, array $runtime = [], array $signal = []): array
    {
        $asset->loadMissing(['brand.customer', 'brand.responsibleUsers']);
        $type = (string) $asset->type;
        $status = $asset->status?->value ?? 'active';
        $operational = array_key_exists($status, self::OPERATIONAL_LABELS) ? $status : 'active';
        $openFindings = $asset->relationLoaded('findings')
            ? $asset->findings->where('status', 'open')->count()
            : $asset->findings()->where('status', 'open')->count();
        $connected = $asset->relationLoaded('assetBindings')
            ? $asset->assetBindings->where('status', 'active')->isNotEmpty()
            : $asset->assetBindings()->where('status', 'active')->exists();
        $typeLabel = self::typeLabel($type);
        $responsible = $asset->brand?->responsibleUsers->map(static fn ($user): array => self::person($user))->values()->all() ?? [];

        $presented = [
            'id' => (string) $asset->id,
            'brand_id' => (string) $asset->brand_id,
            'customer_id' => (string) ($asset->brand?->customer_id ?? ''),
            'customer_name' => $asset->brand?->customer?->name ?? '—',
            'brand_name' => $asset->brand?->name ?? '—',
            'name' => $asset->name,
            'display_name' => self::displayName($asset),
            'stored_name' => self::isNumericName($asset->name) ? trim((string) $asset->name) : null,
            'type' => $type,
            'type_label' => $typeLabel,
            'status' => $status,
            'role' => in_array($type, ['domain', 'hosting'], true) ? 'infrastructure' : 'primary_managed',
            'role_label' => in_array($type, ['domain', 'hosting'], true) ? 'Website infrastructure' : 'Primary managed asset',
            'health' => 'healthy',
            'health_label' => __('operator.states.defined'),
            'connection' => $connected ? 'connected' : 'not_configured',
            'connection_label' => $connected ? __('operator.states.connected') : __('operator.states.defined'),
            'connection_state' => $connected ? 'connected' : 'not_connected',
            'connection_state_label' => $connected ? __('operator.states.connected') : __('operator.states.defined'),
            'operational_status' => $operational,
            'operational_status_label' => self::OPERATIONAL_LABELS[$operational],
            'data_state' => 'unavailable',
            'data_state_label' => __('operator.states.not_collected'),
            'provenance' => __('operator.states.operator_defined'),
            'open_findings' => $openFindings,
            'open_tasks' => 0,
            'last_update' => __('operator.states.never_updated'),
            'last_meaningful_activity' => '',
            'primary_metric_label' => __('operator.forms.status'),
            'primary_metric' => __('operator.states.defined'),
            'route' => self::specialistRoute($type),
            'route_params' => self::specialistRouteParams($asset),
            'url' => self::specialistUrl($asset),
            'sources_url' => route('operator.asset.sources', ['assetId' => $asset->id]),
            'domain' => $asset->domain,
            'primary_url' => $asset->primary_url,
            'cms' => $asset->cms,
            'site_type' => $asset->site_type,
            'languages' => is_array($asset->languages) ? array_values($asset->languages) : [],
            'target_countries' => is_array($asset->target_countries) ? array_values($asset->target_countries) : [],
            'hosting_context' => $asset->hosting_context,
            'module_id' => $asset->module_id,
            'responsible_user_ids' => array_column($responsible, 'id'),
            'responsible_users' => $responsible,
            'attention_priority' => $openFindings > 0 ? 'medium' : 'none',
        ];

        if ($runtime !== []) {
            $connected = (bool) ($runtime['connected'] ?? $connected);
            $presented = array_merge($presented, [
                'connection' => $connected ? 'connected' : 'not_configured',
                'connection_label' => $connected ? __('operator.states.connected') : __('operator.states.not_connected'),
                'connection_state' => $connected ? 'connected' : 'not_connected',
                'connection_state_label' => $connected ? __('operator.states.connected') : __('operator.states.not_connected'),
                'data_state' => (string) ($runtime['data_state'] ?? 'unavailable'),
                'data_state_label' => (string) ($runtime['data_state_label'] ?? __('operator.states.not_collected')),
                'last_update' => (string) ($runtime['last_update'] ?? ''),
                'last_meaningful_activity' => ($runtime['last_sync'] ?? null)?->toIso8601String() ?? '',
                'open_tasks' => (int) ($runtime['open_tasks'] ?? 0),
                'health' => in_array($runtime['data_state'] ?? '', ['stale', 'unavailable'], true) && $connected ? 'needs_attention' : 'healthy',
                'health_label' => in_array($runtime['data_state'] ?? '', ['stale', 'unavailable'], true) && $connected ? __('operator.states.needs_attention') : __('operator.states.defined'),
            ]);
        }

        if ($signal !== []) {
            $presented = array_merge($presented, [
                'open_work' => (int) $signal['open_work'],
                'critical' => (int) $signal['critical'],
                'open_tasks' => (int) $signal['open_work'],
                'sources' => $signal['sources'],
                'tone' => (string) $signal['worst_tone'],
                'data_label' => (string) $signal['data_label'],
                'data_issues' => (int) $signal['data_issues'],
                'reconnect' => (int) $signal['reconnect'],
                'needs_attention' => (bool) $signal['needs_attention'],
                'attention_reason' => $signal['reason'],
                'health' => $signal['needs_attention'] ? 'needs_attention' : 'healthy',
                'health_label' => $signal['needs_attention'] ? 'Dikkat' : 'Sorun yok',
            ]);
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public static function contact(CustomerContact $contact): array
    {
        return [
            'id' => (string) $contact->id,
            'customer_id' => (string) $contact->customer_id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'title' => $contact->title,
            'role' => null,
        ];
    }

    public static function specialistRoute(string $type): string
    {
        return match ($type) {
            'website' => 'operator.website',
            'meta_ads' => 'operator.meta.overview',
            'google_ads' => 'operator.google-ads.overview',
            'google_business_profile', 'gbp' => 'operator.gbp',
            'ga4', 'analytics', 'google_analytics' => 'operator.analytics',
            'gsc', 'search_console' => 'operator.search-console',
            default => 'operator.assets',
        };
    }

    /**
     * @return array<string, int|string>
     */
    public static function specialistRouteParams(DigitalAsset $asset): array
    {
        if (self::specialistRoute((string) $asset->type) === 'operator.assets') {
            return [];
        }

        return ['assetId' => $asset->id];
    }

    public static function specialistUrl(DigitalAsset $asset): string
    {
        $name = self::specialistRoute((string) $asset->type);
        $params = self::specialistRouteParams($asset);

        return $params === [] ? route($name) : route($name, $params);
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    public static function specialistHref(array $asset): string
    {
        if (isset($asset['url']) && is_string($asset['url']) && $asset['url'] !== '') {
            return $asset['url'];
        }

        $name = (string) ($asset['route'] ?? 'operator.assets');
        $id = $asset['id'] ?? $asset['asset_id'] ?? null;
        if ($name === 'operator.assets' || $id === null || $id === '') {
            return route($name);
        }

        return route($name, ['assetId' => $id]);
    }

    public static function derivedModuleId(string $type): ?string
    {
        return match ($type) {
            'website' => 'website',
            'meta_ads' => 'meta-ads',
            'google_ads' => 'google-ads',
            'google_business_profile', 'gbp' => 'google-business-profile',
            'ga4', 'analytics', 'google_analytics' => 'analytics',
            'gsc', 'search_console' => 'search-console',
            'instagram' => 'instagram',
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $assets  presented with a PortfolioSignalsReader signal
     * @return array{managed: int, needs_attention: int, data_issues: int, active_work: int}
     */
    public static function assetsGlance(array $assets): array
    {
        return [
            'managed' => count($assets),
            'needs_attention' => collect($assets)->filter(fn (array $a): bool => (bool) ($a['needs_attention'] ?? false))->count(),
            'data_issues' => collect($assets)->filter(fn (array $a): bool => ((int) ($a['data_issues'] ?? 0)) > 0)->count(),
            'active_work' => collect($assets)->filter(fn (array $a): bool => ((int) ($a['open_work'] ?? 0)) > 0)->count(),
        ];
    }

    /**
     * One row per brand, one cell per channel. With asset signals each present cell carries the data tone of its
     * source (GA4 / Search Console cells read the website's own source), so the matrix agrees with the asset cards.
     *
     * @param  Collection<int, Brand>  $brands
     * @param  array<int, array<string, mixed>>  $assetSignals  PortfolioSignalsReader::forBrands()['assets']
     * @return array<string, mixed>
     */
    public static function estateMatrix(Collection $brands, array $assetSignals = []): array
    {
        $columns = [
            'website' => 'Website',
            'google_business_profile' => 'GBP',
            'google_ads' => 'Google Ads',
            'meta_ads' => 'Meta',
            'ga4' => 'GA4',
            'gsc' => 'GSC',
        ];

        $brands->loadMissing(['digitalAssets.assetBindings', 'customer']);
        $tone = static function (?array $source): array {
            return $source === null ? ['tone' => null, 'label' => null] : ['tone' => $source['tone'], 'label' => $source['state_label']];
        };

        $rows = $brands->map(function (Brand $brand) use ($columns, $assetSignals, $tone): array {
            $byType = $brand->digitalAssets->sortBy('id')->groupBy('type')->map->first();
            $website = $byType->get('website');
            $cells = [];
            foreach ($columns as $type => $label) {
                // GA4 and Search Console are sources of the website: a binding there counts for the brand.
                $capability = ['ga4' => 'ga4', 'gsc' => 'search_console'][$type] ?? null;
                if ($capability !== null && $website !== null && $website->assetBindings->contains(fn ($binding): bool => $binding->capability === $capability && $binding->status === 'active')) {
                    $source = $tone($assetSignals[(int) $website->id]['sources'][$capability] ?? null);
                    $cells[$type] = [
                        'state' => 'present',
                        'tone' => $source['tone'] ?? 'ok',
                        'label' => $source['label'] ?? __('operator.states.connected'),
                        'asset_id' => (string) $website->id,
                        'route' => 'operator.website',
                        'route_params' => ['assetId' => $website->id, 'tab' => $type === 'ga4' ? 'ga4_analysis' : 'search_console'],
                        'url' => route('operator.website', ['assetId' => $website->id, 'tab' => $type === 'ga4' ? 'ga4_analysis' : 'search_console']),
                    ];

                    continue;
                }
                $asset = $byType->get($type);
                if ($asset === null && $type === 'google_business_profile') {
                    $asset = $byType->get('gbp');
                }
                if ($asset === null) {
                    $cells[$type] = ['state' => 'not_configured', 'label' => __('operator.states.not_configured')];

                    continue;
                }
                $signal = $assetSignals[(int) $asset->id] ?? null;
                $cells[$type] = [
                    'state' => 'present',
                    'tone' => $signal !== null && ($signal['sources'] ?? []) !== [] ? $signal['worst_tone'] : null,
                    'label' => $signal !== null && ($signal['sources'] ?? []) !== [] ? $signal['data_label'] : __('operator.states.defined'),
                    'asset_id' => (string) $asset->id,
                    'route' => self::specialistRoute($asset->type),
                    'route_params' => self::specialistRouteParams($asset),
                    'url' => self::specialistUrl($asset),
                ];
            }

            return [
                'brand_id' => (string) $brand->id,
                'brand' => $brand->name,
                'customer' => $brand->customer?->name ?? '—',
                'cells' => $cells,
            ];
        })->values()->all();

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }
}
