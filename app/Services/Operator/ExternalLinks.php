<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that turns what MoxDOP knows about an asset (its bindings' external ids, the site address, a WordPress
 * post id, a campaign id) into a link to the same thing in the provider's own screen (yakup 2026-10-20: "ilgili
 * varlıklara giriş butonları"). Every "Aç ↗" in the panel comes from here, so the target is always the deepest one the
 * stored ids allow: a campaign in Google Ads, an ad in Ads Manager, a page in Search Console, a post's editor in WordPress.
 *
 * A WordPress link signs in through the connector's one-click login (ADR-068, `login` set): the panel POSTs to
 * `operator.integrations.wordpress-login` with the wp-admin screen to open; without login it falls back to /wp-admin.
 * Nothing here calls a provider: links only.
 *
 * @phpstan-type ExternalLink array{key: string, label: string, hint: string, url: string, login: array{site: int, to: string}|null}
 */
final class ExternalLinks
{
    public const string GBP_MANAGER = 'https://business.google.com/locations';

    /** @var array<int, true>|null connector sites of the brand forBrand() reads (null: look each site up) */
    private ?array $wordPressSites = null;

    /** @var array<int, string>|null latest place id per profile resource of that brand */
    private ?array $placeIds = null;

    /**
     * Links for one asset: its own account (and for a website the site, WordPress and the Search Console / GA4 bound to it).
     *
     * @return list<ExternalLink>
     */
    public function forAsset(DigitalAsset $asset): array
    {
        $links = [];
        if ($asset->type === 'website') {
            $links[] = $this->site($asset);
            if ($this->isWordPress($asset)) {
                $links[] = $this->wordPressAdmin($asset);
            }
        }

        $bindings = $asset->relationLoaded('assetBindings')
            ? $asset->assetBindings->where('status', 'active')->sortBy('id')
            : CoreAssetBinding::query()->with('externalResource')->where('digital_asset_id', $asset->id)->where('status', 'active')->orderBy('id')->get();
        foreach ($bindings as $binding) {
            $resource = $binding->externalResource;
            if ($resource === null) {
                continue;
            }
            $link = match ((string) $binding->capability) {
                'google_ads' => $this->googleAdsAccount((string) $resource->external_id, (string) $resource->display_name),
                'meta_ads' => $this->metaAccount((string) $resource->external_id, (string) $resource->display_name),
                'search_console' => $this->searchConsoleProperty((string) $resource->external_id),
                'ga4' => $this->analyticsProperty((string) $resource->external_id, (string) $resource->display_name),
                'google_business_profile' => $this->businessProfileMaps($this->placeId((int) $resource->id, $resource->metadata), (string) $resource->display_name),
                default => null,
            };
            if ($link !== null) {
                $links[] = $link;
            }
        }
        if ($asset->type === 'google_business_profile') {
            $links[] = $this->businessProfileManager();
        }

        return $this->unique($links);
    }

    /**
     * Every link of a brand's assets for the brand page's "Hızlı giriş" menu, grouped per kind in a fixed order. A brand
     * with many profiles (Avrupadent's 17 branches) gets one "İşletme profilleri" link, not 17 map links.
     *
     * @return list<array{group: string, links: list<ExternalLink>}>
     */
    public function forBrand(Brand $brand): array
    {
        $assets = DigitalAsset::query()->with('assetBindings.externalResource')->where('brand_id', $brand->id)
            ->whereNotIn('type', ['domain', 'hosting'])->orderBy('type')->orderBy('name')->get();
        $groups = ['site' => [], 'wordpress' => [], 'google_ads' => [], 'meta_ads' => [], 'gbp' => [], 'search_console' => [], 'ga4' => []];
        $profiles = $assets->where('type', 'google_business_profile')->count();
        $this->preload($assets);
        foreach ($assets as $asset) {
            if ($profiles > 1 && $asset->type === 'google_business_profile') {
                continue;
            }
            foreach ($this->forAsset($asset) as $link) {
                $group = str_starts_with($link['key'], 'gbp') ? 'gbp' : explode(':', $link['key'])[0];
                if (array_key_exists($group, $groups)) {
                    $groups[$group][] = $link;
                }
            }
        }
        if ($profiles > 1) {
            $groups['gbp'] = [array_merge($this->businessProfileManager(), ['label' => 'İşletme profilleri ('.$profiles.')'])];
        }
        $names = ['site' => 'Web sitesi', 'wordpress' => 'WordPress', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta', 'gbp' => 'İşletme profili', 'search_console' => 'Search Console', 'ga4' => 'Google Analytics'];

        return collect($groups)->map(fn (array $links, string $group): array => ['group' => $names[$group], 'links' => $this->unique($links)])
            ->filter(fn (array $group): bool => $group['links'] !== [])->values()->all();
    }

    /** @return ExternalLink */
    public function site(DigitalAsset $site): array
    {
        return $this->link('site:'.$site->id, $site->domain ?: (string) $site->name, 'Siteyi yeni sekmede aç', $this->siteBase($site).'/');
    }

    /** @return ExternalLink WordPress admin, signed in with the connector's one-click login */
    public function wordPressAdmin(DigitalAsset $site): array
    {
        return $this->wordPress($site, 'index.php', 'WordPress paneli', 'WordPress paneline giriş yaparak aç');
    }

    /** @return ExternalLink one post's (or page's) editor in WordPress, signed in */
    public function wordPressPost(DigitalAsset $site, int $postId): array
    {
        return $this->wordPress($site, 'post.php?post='.$postId.'&action=edit', 'WordPress’te düzenle', 'Bu yazının WordPress düzenleme ekranını giriş yaparak aç');
    }

    /** @return ExternalLink|null */
    public function googleAdsAccount(string $customerId, string $name = ''): ?array
    {
        $customer = $this->digits($customerId);

        return $customer === '' ? null : $this->link('google_ads:'.$customer, $name !== '' ? $name : $this->dashed($customer), 'Google Ads hesabını aç ('.$this->dashed($customer).')',
            'https://ads.google.com/aw/overview?__e='.$customer);
    }

    /** @return ExternalLink|null */
    public function googleAdsCampaign(string $customerId, string $campaignId, string $name = ''): ?array
    {
        $customer = $this->digits($customerId);
        $campaign = $this->digits($campaignId);
        if ($customer === '' || $campaign === '') {
            return $customer === '' ? null : $this->googleAdsAccount($customer);
        }

        return $this->link('google_ads:'.$customer.':'.$campaign, $name !== '' ? $name : 'Kampanya', 'Bu kampanyayı Google Ads’te aç',
            'https://ads.google.com/aw/adgroups?campaignId='.$campaign.'&__e='.$customer);
    }

    /** @return ExternalLink|null one ad group's keywords in Google Ads */
    public function googleAdsAdGroup(string $customerId, string $campaignId, string $adGroupId): ?array
    {
        $customer = $this->digits($customerId);
        $campaign = $this->digits($campaignId);
        $adGroup = $this->digits($adGroupId);
        if ($customer === '' || $campaign === '' || $adGroup === '') {
            return $this->googleAdsCampaign($customerId, $campaignId, 'Google Ads');
        }

        return $this->link('google_ads:'.$customer.':'.$campaign.':'.$adGroup, 'Google Ads', 'Bu reklam grubunu Google Ads’te aç',
            'https://ads.google.com/aw/keywords?campaignId='.$campaign.'&adGroupId='.$adGroup.'&__e='.$customer);
    }

    /** @return ExternalLink|null */
    public function metaAccount(string $accountId, string $name = ''): ?array
    {
        $account = $this->digits($accountId);

        return $account === '' ? null : $this->link('meta_ads:'.$account, $name !== '' ? $name : 'act_'.$account, 'Reklam Yöneticisi’nde bu hesabı aç',
            'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act='.$account);
    }

    /** @return ExternalLink|null */
    public function metaCampaign(string $accountId, string $campaignId): ?array
    {
        $account = $this->digits($accountId);
        $campaign = $this->digits($campaignId);
        if ($account === '' || $campaign === '') {
            return $this->metaAccount($accountId);
        }

        return $this->link('meta_ads:'.$account.':'.$campaign, 'Reklam Yöneticisi', 'Bu kampanyayı Reklam Yöneticisi’nde aç',
            'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act='.$account.'&selected_campaign_ids='.$campaign);
    }

    /** @return ExternalLink|null */
    public function searchConsoleProperty(string $property): ?array
    {
        $property = trim($property);

        return $property === '' ? null : $this->link('search_console:'.$property, $this->propertyLabel($property), 'Search Console’da bu mülkü aç',
            'https://search.google.com/search-console?resource_id='.rawurlencode($property));
    }

    /** @return ExternalLink|null one page's search performance in Search Console */
    public function searchConsolePage(string $property, string $pageUrl): ?array
    {
        $property = trim($property);
        $pageUrl = trim($pageUrl);
        if ($property === '' || $pageUrl === '') {
            return null;
        }

        return $this->link('search_console:'.$property.':'.$pageUrl, 'Search Console', 'Bu sayfanın aramalarını Search Console’da aç',
            'https://search.google.com/search-console/performance/search-analytics?resource_id='.rawurlencode($property).'&page='.rawurlencode('!'.$pageUrl));
    }

    /** @return ExternalLink|null one query's performance in Search Console */
    public function searchConsoleQuery(string $property, string $query): ?array
    {
        $property = trim($property);
        $query = trim($query);
        if ($property === '' || $query === '') {
            return null;
        }

        return $this->link('search_console:'.$property.':q:'.$query, 'Search Console', 'Bu sorguyu Search Console’da aç',
            'https://search.google.com/search-console/performance/search-analytics?resource_id='.rawurlencode($property).'&query='.rawurlencode('!'.$query));
    }

    /** @return ExternalLink|null */
    public function analyticsProperty(string $propertyId, string $name = ''): ?array
    {
        $property = $this->digits($propertyId);

        return $property === '' ? null : $this->link('ga4:'.$property, $name !== '' ? $name : 'GA4 '.$property, 'Google Analytics’te bu mülkü aç',
            'https://analytics.google.com/analytics/web/#/p'.$property.'/reports/intelligenthome');
    }

    /** @return ExternalLink|null the profile on Google Maps (the owner, signed in, edits it there) */
    public function businessProfileMaps(?string $placeId, string $name = ''): ?array
    {
        $placeId = trim((string) $placeId);

        return $placeId === '' ? null : $this->link('gbp:'.$placeId, $name !== '' ? $name : 'Haritada aç', 'Profili Google Haritalar’da aç (Google hesabınla girişliysen buradan düzenlenir)',
            'https://www.google.com/maps/place/?q=place_id:'.rawurlencode($placeId));
    }

    /** @return ExternalLink */
    public function businessProfileManager(): array
    {
        return $this->link('gbp:manager', 'Profilleri yönet', 'Google İşletme Profili yöneticisini aç', self::GBP_MANAGER);
    }

    /**
     * The Search Console property bound to a website (null when none is bound): page and query links need it.
     */
    public function searchConsolePropertyFor(int $websiteAssetId): ?string
    {
        $property = DB::table('core_asset_bindings')
            ->join('core_external_resources', 'core_external_resources.id', '=', 'core_asset_bindings.external_resource_id')
            ->where('core_asset_bindings.digital_asset_id', $websiteAssetId)
            ->where('core_asset_bindings.capability', 'search_console')
            ->where('core_asset_bindings.status', 'active')
            ->value('core_external_resources.external_id');

        return is_string($property) && trim($property) !== '' ? trim($property) : null;
    }

    /** WordPress sites (by cms or a paired connector) get the one-click login link. */
    public function isWordPress(DigitalAsset $site): bool
    {
        return strtolower((string) $site->cms) === 'wordpress'
            || ($this->wordPressSites !== null ? isset($this->wordPressSites[(int) $site->id])
                : DB::table('core_connections')->where('digital_asset_id', $site->id)->where('type', 'wordpress_connector')->where('enabled', true)->exists());
    }

    /**
     * A brand's connector sites and profile place ids in one query each, so its "Hızlı giriş" menu costs the same
     * number of queries for one website or profile as for many.
     *
     * @param  Collection<int, DigitalAsset>  $assets
     */
    private function preload(Collection $assets): void
    {
        $this->wordPressSites = DB::table('core_connections')->whereIn('digital_asset_id', $assets->where('type', 'website')->pluck('id')->all() ?: [0])
            ->where('type', 'wordpress_connector')->where('enabled', true)->pluck('digital_asset_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
        $resources = $assets->flatMap(fn (DigitalAsset $asset) => $asset->assetBindings)->where('capability', 'google_business_profile')->pluck('external_resource_id')->filter()->unique()->values()->all();
        $this->placeIds = [];
        DB::table('gbp_location_snapshots')->whereIn('external_resource_id', $resources ?: [0])->whereNotNull('place_id')
            ->orderBy('captured_at')->orderBy('id')->get(['external_resource_id', 'place_id'])
            ->each(function (object $row): void {
                $this->placeIds[(int) $row->external_resource_id] = (string) $row->place_id; // the latest snapshot wins
            });
    }

    public function siteBase(DigitalAsset $site): string
    {
        $base = trim((string) ($site->primary_url ?: $site->domain));
        if ($base !== '' && ! preg_match('~^https?://~i', $base)) {
            $base = 'https://'.$base;
        }
        $parts = parse_url($base);

        return isset($parts['host']) ? ($parts['scheme'] ?? 'https').'://'.$parts['host'] : rtrim($base, '/');
    }

    /** @return ExternalLink */
    private function wordPress(DigitalAsset $site, string $to, string $label, string $hint): array
    {
        return $this->link('wordpress:'.$site->id.':'.$to, $label, $hint, $this->siteBase($site).'/wp-admin/'.$to, ['site' => (int) $site->id, 'to' => $to]);
    }

    /**
     * @param  array{site: int, to: string}|null  $login
     * @return ExternalLink
     */
    private function link(string $key, string $label, string $hint, string $url, ?array $login = null): array
    {
        return ['key' => $key, 'label' => $label, 'hint' => $hint, 'url' => $url, 'login' => $login];
    }

    private function placeId(int $resourceId, mixed $metadata): ?string
    {
        $placeId = ($this->placeIds !== null ? ($this->placeIds[$resourceId] ?? null) : DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->whereNotNull('place_id')
            ->orderByDesc('captured_at')->orderByDesc('id')->value('place_id')) ?? data_get($metadata, 'place_id');

        return is_string($placeId) && trim($placeId) !== '' ? trim($placeId) : null;
    }

    private function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private function dashed(string $customer): string
    {
        return strlen($customer) === 10 ? substr($customer, 0, 3).'-'.substr($customer, 3, 3).'-'.substr($customer, 6) : $customer;
    }

    private function propertyLabel(string $property): string
    {
        return str_starts_with($property, 'sc-domain:') ? substr($property, 10) : (string) (parse_url($property, PHP_URL_HOST) ?: $property);
    }

    /**
     * @param  list<ExternalLink|null>  $links
     * @return list<ExternalLink>
     */
    private function unique(array $links): array
    {
        return (new Collection($links))->filter()->unique('key')->values()->all();
    }
}
