<?php

namespace App\Services\Intel;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Intel\BrandIntelSetting;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\BrandSetup\BrandSetupMatcher;
use Illuminate\Support\Facades\DB;

/**
 * How the brand's own business is recognised in Google Maps results: place id / cid (settings first, then the
 * latest collected Business Profile snapshot), website hosts and phone numbers; plus the profile's map pin.
 */
final class BrandGbpIdentity
{
    /**
     * @return array{title: ?string, place_id: ?string, cid: ?string, lat: ?float, lng: ?float, hosts: list<string>, phones: list<string>, address: ?string}
     */
    public function for(Brand $brand): array
    {
        $settings = BrandIntelSetting::query()->where('brand_id', $brand->id)->first();
        $snapshot = $this->snapshot($brand);
        $latlng = $snapshot !== null ? GoogleAdsAdvisorInputCollector::decode($snapshot->latlng) : [];
        $phones = [];
        if ($snapshot !== null) {
            $data = GoogleAdsAdvisorInputCollector::decode($snapshot->phone_numbers);
            array_walk_recursive($data, static function (mixed $value) use (&$phones): void {
                if (is_string($value) && ($key = WhatsAppContactLinker::key($value)) !== null) {
                    $phones[] = $key;
                }
            });
        }
        $hosts = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get(['primary_url', 'domain'])
            ->flatMap(fn (DigitalAsset $site): array => [BrandSetupMatcher::host((string) $site->domain), BrandSetupMatcher::host((string) $site->primary_url)])
            ->push($snapshot !== null ? BrandSetupMatcher::host((string) $snapshot->website_uri) : '')
            ->map(fn (string $host): string => preg_replace('/^www\./', '', $host) ?? $host)
            ->filter()->unique()->values()->all();
        $address = $snapshot !== null ? GoogleAdsAdvisorInputCollector::decode($snapshot->storefront_address) : [];

        return [
            'title' => $snapshot?->title ?? $brand->name,
            'place_id' => $settings?->gbp_place_id ?: ($snapshot?->place_id ?: null),
            'cid' => $settings?->gbp_cid ?: self::cidFromMapsUri((string) ($snapshot?->maps_uri ?? '')),
            'lat' => is_numeric($latlng['latitude'] ?? null) ? (float) $latlng['latitude'] : null,
            'lng' => is_numeric($latlng['longitude'] ?? null) ? (float) $latlng['longitude'] : null,
            'hosts' => $hosts,
            'phones' => array_values(array_unique($phones)),
            'address' => $address !== [] ? trim(implode(', ', array_filter([implode(' ', (array) ($address['addressLines'] ?? [])), $address['sublocality'] ?? null, $address['locality'] ?? null, $address['administrativeArea'] ?? null]))) : null,
        ];
    }

    /**
     * Is this Maps result the brand's own business?
     *
     * @param  array<string, mixed>  $item
     * @param  array{place_id: ?string, cid: ?string, hosts: list<string>, phones: list<string>}  $identity
     */
    public static function matches(array $item, array $identity): bool
    {
        if ($identity['place_id'] !== null && ($item['place_id'] ?? null) === $identity['place_id']) {
            return true;
        }
        if ($identity['cid'] !== null && (string) ($item['cid'] ?? '') === $identity['cid']) {
            return true;
        }
        $host = preg_replace('/^www\./', '', BrandSetupMatcher::host((string) ($item['domain'] ?? $item['url'] ?? ''))) ?? '';
        if ($host !== '' && in_array($host, $identity['hosts'], true)) {
            return true;
        }
        $phone = WhatsAppContactLinker::key((string) ($item['phone'] ?? ''));

        return $phone !== null && in_array($phone, $identity['phones'], true);
    }

    public static function cidFromMapsUri(string $uri): ?string
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $cid = $query['cid'] ?? null;

        return is_string($cid) && preg_match('/^\d{5,25}$/', $cid) === 1 ? $cid : null;
    }

    private function snapshot(Brand $brand): ?object
    {
        $resourceIds = CoreAssetBinding::query()
            ->whereIn('digital_asset_id', DigitalAsset::query()->where('brand_id', $brand->id)->whereIn('type', ['google_business_profile', 'gbp'])->select('id'))
            ->where('capability', 'google_business_profile')->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->pluck('external_resource_id');
        if ($resourceIds->isEmpty()) {
            return null;
        }

        return DB::table('gbp_location_snapshots')->whereIn('external_resource_id', $resourceIds)->orderByDesc('captured_at')->orderByDesc('id')->first();
    }
}
