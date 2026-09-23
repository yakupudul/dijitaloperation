<?php

namespace App\Services\BrandSetup;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\Providers\Ga4\Ga4ApiClient;
use App\Services\SeoTasks\SeoText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deterministic part of "Otomatik kur": proposes the website asset and resource bindings.
 * Domain matches (Search Console, GA4 web stream, Business Profile website) are near-certain;
 * Google Ads / Meta ad accounts carry no URL, so they are matched by name and left unticked
 * unless the name match is strong. Reads only; nothing is written here except the GA4 stream cache.
 */
final class BrandSetupMatcher
{
    private const int GA4_STREAM_LOOKUPS = 40;

    private const int GA4_STREAM_CACHE_DAYS = 7;

    public function __construct(private readonly Ga4ApiClient $ga4) {}

    /** @return list<array<string, mixed>> */
    public function propose(Brand $brand, string $websiteUrl): array
    {
        $host = self::host($websiteUrl);
        $items = [];
        $website = $this->websiteAsset($brand, $host);

        $items[] = [
            'key' => 'asset:website',
            'kind' => 'asset',
            'group' => 'website',
            'label' => 'Web sitesi varlığı: '.$host,
            'asset_id' => $website?->id,
            'url' => $this->canonicalUrl($websiteUrl),
            'status' => $website !== null ? 'already' : 'proposed',
            'confidence' => 1.0,
            'reason' => $website !== null ? 'Markada bu alan adıyla bir web sitesi varlığı zaten var.' : 'Girilen adresle yeni web sitesi varlığı açılacak.',
            'selected' => $website === null,
        ];

        $boundElsewhere = CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->get(['external_resource_id', 'digital_asset_id'])
            ->groupBy('external_resource_id');
        $brandAssetIds = $brand->digitalAssets()->pluck('id')->all();

        array_push($items, ...$this->searchConsole($host, $boundElsewhere, $brandAssetIds));
        array_push($items, ...$this->ga4($brand, $host, $boundElsewhere, $brandAssetIds));
        array_push($items, ...$this->businessProfile($brand, $host, $boundElsewhere, $brandAssetIds));
        array_push($items, ...$this->byName('google_ads', 'Google Ads', $brand, $host, $boundElsewhere, $brandAssetIds));
        array_push($items, ...$this->byName('meta_ads', 'Meta reklam hesabı', $brand, $host, $boundElsewhere, $brandAssetIds));

        return $items;
    }

    public static function host(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return rtrim(preg_replace('/^www\./', '', $host) ?? $host, '.');
    }

    /** Distinctive part of the domain: "adadent.com.tr" → "adadent". */
    public static function domainRoot(string $host): string
    {
        $labels = explode('.', $host);
        $suffixes = ['com', 'net', 'org', 'tr', 'gov', 'edu', 'biz', 'info', 'co', 'uk', 'de', 'io', 'av', 'bel', 'gen', 'k12', 'web', 'tv'];
        while (count($labels) > 1 && in_array(end($labels), $suffixes, true)) {
            array_pop($labels);
        }

        return (string) end($labels);
    }

    /** 0..1 similarity of an account name to the brand (name tokens or compacted domain root). */
    public static function nameScore(string $candidate, string $brandName, string $host): float
    {
        $folded = SeoText::fold($candidate);
        if ($folded === '') {
            return 0.0;
        }
        $compact = str_replace(' ', '', $folded);
        $root = SeoText::fold(self::domainRoot($host));
        $brandCompact = str_replace(' ', '', SeoText::fold($brandName));
        $score = 0.0;
        if ($root !== '' && mb_strlen($root) >= 4 && str_contains($compact, str_replace(' ', '', $root))) {
            $score = 0.9;
        }
        if ($brandCompact !== '' && mb_strlen($brandCompact) >= 4 && str_contains($compact, $brandCompact)) {
            $score = max($score, 0.9);
        }
        $score = max($score, 0.8 * SeoText::tokenOverlap($candidate, $brandName));
        if ($host !== '' && str_contains(mb_strtolower($candidate), $host)) {
            $score = 0.95;
        }

        return round(min(1.0, $score), 2);
    }

    private function websiteAsset(Brand $brand, string $host): ?DigitalAsset
    {
        return $brand->digitalAssets()->where('type', 'website')->get()
            ->first(fn (DigitalAsset $asset): bool => self::host((string) ($asset->primary_url ?: $asset->domain)) === $host);
    }

    private function canonicalUrl(string $url): string
    {
        $url = trim($url);
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').'/';
    }

    /** @return Collection<int, CoreExternalResource> */
    private function resources(string $type, ?string $provider = null): Collection
    {
        return CoreExternalResource::query()
            ->with('integration')
            ->where('resource_type', $type)
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->whereHas('integration', fn ($q) => $q->where('status', CoreIntegration::STATUS_ACTIVE))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, CoreAssetBinding>>  $bound
     * @param  list<int>  $brandAssetIds
     * @return array{status: string, asset_id: ?int}
     */
    private function bindingState(CoreExternalResource $resource, $bound, array $brandAssetIds): array
    {
        $bindings = $bound->get($resource->id);
        if ($bindings === null || $bindings->isEmpty()) {
            return ['status' => 'proposed', 'asset_id' => null];
        }
        $assetId = (int) $bindings->first()->digital_asset_id;

        return ['status' => in_array($assetId, $brandAssetIds, true) ? 'already' : 'bound_elsewhere', 'asset_id' => $assetId];
    }

    /** @return array<string, mixed> */
    private function item(string $group, string $capability, CoreExternalResource $resource, string $target, float $confidence, string $reason, array $state, float $selectAt = 0.85): array
    {
        return [
            'key' => $capability.':'.$resource->id,
            'kind' => 'bind',
            'group' => $group,
            'capability' => $capability,
            'resource_id' => $resource->id,
            'label' => $resource->display_name.' ('.$resource->external_id.')',
            'target' => $target, // website | new:<asset type>
            'status' => $state['status'],
            'bound_asset_id' => $state['asset_id'],
            'confidence' => round($confidence, 2),
            'reason' => $reason,
            'selected' => $state['status'] === 'proposed' && $confidence >= $selectAt,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function searchConsole(string $host, $bound, array $brandAssetIds): array
    {
        $matches = [];
        foreach ($this->resources('search_console', 'google') as $resource) {
            $siteUrl = (string) ($resource->metadata['site_url'] ?? $resource->external_id);
            if (str_starts_with($siteUrl, 'sc-domain:')) {
                $domain = mb_strtolower(substr($siteUrl, strlen('sc-domain:')));
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    $matches[] = [$resource, 0.99, 'Search Console alan adı mülkü birebir: '.$siteUrl];
                }
            } elseif (self::host($siteUrl) === $host) {
                $matches[] = [$resource, 0.95, 'Search Console URL mülkü aynı alan adında: '.$siteUrl];
            }
        }
        usort($matches, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        $items = [];
        foreach ($matches as $index => [$resource, $confidence, $reason]) {
            // Only one Search Console property per website; the best one is ticked.
            $items[] = $this->item('search_console', 'search_console', $resource, 'website', $index === 0 ? $confidence : min($confidence, 0.6), $index === 0 ? $reason : $reason.' (ikinci aday)', $this->bindingState($resource, $bound, $brandAssetIds));
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function ga4(Brand $brand, string $host, $bound, array $brandAssetIds): array
    {
        $root = self::domainRoot($host);
        $resources = $this->resources('ga4', 'google');
        // Look up web streams for the most likely properties first; cache results on the resource.
        $ordered = $resources->sortByDesc(function (CoreExternalResource $resource) use ($brand, $host): float {
            $label = $resource->display_name.' '.($resource->metadata['account_display_name'] ?? '');

            return self::nameScore($label, (string) $brand->name, $host);
        })->values();

        $items = [];
        $lookups = 0;
        foreach ($ordered as $resource) {
            $uris = $this->webStreamUris($resource, $lookups);
            $state = $this->bindingState($resource, $bound, $brandAssetIds);
            $streamHit = collect($uris)->first(fn (string $uri): bool => self::host($uri) === $host);
            if ($streamHit !== null) {
                $items[] = $this->item('ga4', 'ga4', $resource, 'website', 0.97, 'GA4 web veri akışı adresi birebir: '.$streamHit, $state);

                continue;
            }
            $label = $resource->display_name.' '.($resource->metadata['account_display_name'] ?? '');
            $score = self::nameScore($label, (string) $brand->name, $host);
            if ($uris === [] && $score >= 0.8 && $root !== '') {
                $items[] = $this->item('ga4', 'ga4', $resource, 'website', 0.7, 'Veri akışı okunamadı; mülk adı markayla eşleşiyor: '.$resource->display_name, $state);
            }
        }
        usort($items, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
        foreach ($items as $index => &$item) {
            if ($index > 0) {
                $item['selected'] = false; // one GA4 property per website
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function webStreamUris(CoreExternalResource $resource, int &$lookups): array
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $checkedAt = isset($meta['web_streams_checked_at']) ? strtotime((string) $meta['web_streams_checked_at']) : false;
        if ($checkedAt !== false && $checkedAt >= now()->subDays(self::GA4_STREAM_CACHE_DAYS)->getTimestamp()) {
            return array_values(array_filter((array) ($meta['web_stream_uris'] ?? []), 'is_string'));
        }
        if ($lookups >= self::GA4_STREAM_LOOKUPS || ! $resource->integration instanceof CoreIntegration) {
            return array_values(array_filter((array) ($meta['web_stream_uris'] ?? []), 'is_string'));
        }
        $lookups++;
        try {
            $response = $this->ga4->listDataStreams($resource->integration, (string) $resource->external_id, ['pageSize' => 200]);
            if (! $response->successful()) {
                return [];
            }
            $uris = [];
            foreach ((array) $response->json('dataStreams') as $stream) {
                $uri = data_get($stream, 'webStreamData.defaultUri');
                if (is_string($uri) && $uri !== '') {
                    $uris[] = $uri;
                }
            }
            $meta['web_stream_uris'] = $uris;
            $meta['web_streams_checked_at'] = now()->toIso8601String();
            $resource->forceFill(['metadata' => $meta])->save();

            return $uris;
        } catch (Throwable $exception) {
            Log::info('GA4 data stream lookup failed during brand setup.', ['resource_id' => $resource->id, 'error' => $exception->getMessage()]);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function businessProfile(Brand $brand, string $host, $bound, array $brandAssetIds): array
    {
        $items = [];
        foreach ($this->resources('google_business_profile', 'google') as $resource) {
            $site = (string) ($resource->metadata['website_uri'] ?? '');
            $state = $this->bindingState($resource, $bound, $brandAssetIds);
            if ($site !== '' && self::host($site) === $host) {
                $items[] = $this->item('google_business_profile', 'google_business_profile', $resource, 'new:google_business_profile', 0.95, 'İşletme Profilindeki web sitesi aynı alan adı: '.$site, $state);

                continue;
            }
            $score = self::nameScore((string) $resource->display_name, (string) $brand->name, $host);
            if ($score >= 0.8) {
                $items[] = $this->item('google_business_profile', 'google_business_profile', $resource, 'new:google_business_profile', 0.6, 'Profil adı markaya benziyor (web sitesi alanı farklı veya boş).', $state);
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function byName(string $type, string $label, Brand $brand, string $host, $bound, array $brandAssetIds): array
    {
        $provider = $type === 'meta_ads' ? 'meta' : 'google';
        $items = [];
        foreach ($this->resources($type, $provider) as $resource) {
            $meta = is_array($resource->metadata) ? $resource->metadata : [];
            if (($meta['is_manager'] ?? false) === true || ($meta['selectable'] ?? true) === false || ($meta['bindable'] ?? true) === false) {
                continue;
            }
            $name = trim($resource->display_name.' '.($meta['descriptive_name'] ?? '').' '.($meta['business_name'] ?? ''));
            $score = self::nameScore($name, (string) $brand->name, $host);
            if ($score < 0.6) {
                continue;
            }
            $items[] = $this->item($type, $type, $resource, 'new:'.$type, $score, $label.' adı markaya benziyor ('.(int) round($score * 100).'%). Hesaplarda web sitesi bilgisi olmadığı için kontrol et.', $this->bindingState($resource, $bound, $brandAssetIds), 0.9);
        }
        usort($items, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $items;
    }
}
