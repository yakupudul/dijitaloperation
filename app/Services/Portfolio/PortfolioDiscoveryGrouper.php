<?php

namespace App\Services\Portfolio;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;

/**
 * "Keşfet ve Grupla": groups discovered, not-yet-bound provider accounts into proposed brands.
 * Accounts that carry a web address (Search Console, GA4 web stream, Business Profile website) are grouped by
 * domain; accounts without one (Google Ads, Meta, GA4 without a cached stream) join the group whose name they
 * match, otherwise they form name groups. Deterministic, read-only, no provider calls.
 */
final class PortfolioDiscoveryGrouper
{
    public const array TYPES = ['search_console', 'ga4', 'google_business_profile', 'google_ads', 'meta_ads'];

    private const array TYPE_LABELS = [
        'search_console' => 'Search Console',
        'ga4' => 'Google Analytics 4',
        'google_business_profile' => 'İşletme Profili',
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta reklam hesabı',
    ];

    private const float NAME_MATCH = 0.8;

    /**
     * @return list<array{key: string, host: ?string, suggested_brand: string, existing_brand_id: ?int, existing_brand: ?string, existing_customer_id: ?int, resources: list<array{id: int, type: string, type_label: string, label: string, external_id: string, target: string, reason: string, selected: bool}>}>
     */
    public function groups(): array
    {
        $resources = $this->unboundResources();
        $existingSites = $this->existingWebsites();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        $nameOnly = [];
        foreach ($resources as $resource) {
            $host = $this->hostOf($resource);
            if ($host === null) {
                $nameOnly[] = $resource;

                continue;
            }
            $key = 'host:'.$host;
            $groups[$key] ??= $this->emptyGroup($key, $host, $existingSites[$host] ?? null);
            $groups[$key]['members'][] = [$resource, 'Aynı alan adı: '.$host];
        }

        foreach ($groups as &$group) {
            $group['suggested_brand'] = $this->suggestedName($group);
        }
        unset($group);

        foreach ($nameOnly as $resource) {
            $label = $this->nameOf($resource);
            $best = null;
            $bestScore = 0.0;
            foreach ($groups as $key => $group) {
                $score = BrandSetupMatcher::nameScore($label, (string) $group['suggested_brand'], (string) ($group['host'] ?? ''));
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$key, $score];
                }
            }
            if ($best !== null && $bestScore >= self::NAME_MATCH) {
                $groups[$best]['members'][] = [$resource, sprintf('Hesap adı markaya benziyor (%%%d).', (int) round($bestScore * 100))];

                continue;
            }
            $key = 'name:'.str_replace(' ', '', SeoText::fold($this->displayName($resource)));
            $groups[$key] ??= $this->emptyGroup($key, null, null) + ['suggested_brand' => $this->displayName($resource)];
            $groups[$key]['members'][] = [$resource, 'Web adresi yok; hesap adına göre gruplandı.'];
        }

        return collect($groups)
            ->map(fn (array $group): array => $this->present($group))
            ->sortBy([
                fn (array $a, array $b): int => ($b['host'] !== null) <=> ($a['host'] !== null),
                fn (array $a, array $b): int => count($b['resources']) <=> count($a['resources']),
                fn (array $a, array $b): int => strcmp(mb_strtolower($a['suggested_brand']), mb_strtolower($b['suggested_brand'])),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, CoreExternalResource> */
    private function unboundResources(): Collection
    {
        $bound = CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id')->all();

        return CoreExternalResource::query()
            ->whereIn('resource_type', self::TYPES)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->whereHas('integration', fn ($query) => $query->where('status', CoreIntegration::STATUS_ACTIVE))
            ->whereNotIn('id', $bound)
            ->orderBy('id')
            ->get()
            ->reject(function (CoreExternalResource $resource): bool {
                $meta = is_array($resource->metadata) ? $resource->metadata : [];

                return ($meta['is_manager'] ?? false) === true || ($meta['selectable'] ?? true) === false || ($meta['bindable'] ?? true) === false;
            })
            ->values();
    }

    /** @return array<string, DigitalAsset> host => website asset already in the portfolio */
    private function existingWebsites(): array
    {
        $sites = [];
        DigitalAsset::query()->with('brand')->where('type', 'website')->get()
            ->each(function (DigitalAsset $asset) use (&$sites): void {
                $host = BrandSetupMatcher::host((string) ($asset->primary_url ?: $asset->domain));
                if ($host !== '' && ! isset($sites[$host])) {
                    $sites[$host] = $asset;
                }
            });

        return $sites;
    }

    private function hostOf(CoreExternalResource $resource): ?string
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $url = match ($resource->resource_type) {
            'search_console' => $this->searchConsoleUrl((string) ($meta['site_url'] ?? $resource->external_id)),
            'ga4' => (string) (collect((array) ($meta['web_stream_uris'] ?? []))->first(fn ($uri): bool => is_string($uri) && $uri !== '') ?? ''),
            'google_business_profile' => (string) ($meta['website_uri'] ?? ''),
            default => '',
        };
        $host = BrandSetupMatcher::host($url);

        return $host !== '' ? $host : null;
    }

    private function searchConsoleUrl(string $siteUrl): string
    {
        return str_starts_with($siteUrl, 'sc-domain:') ? substr($siteUrl, strlen('sc-domain:')) : $siteUrl;
    }

    private function nameOf(CoreExternalResource $resource): string
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];

        return trim($resource->display_name.' '.($meta['descriptive_name'] ?? '').' '.($meta['business_name'] ?? '').' '.($meta['account_display_name'] ?? ''));
    }

    private function displayName(CoreExternalResource $resource): string
    {
        return trim((string) ($resource->display_name ?: $resource->external_id));
    }

    /** @return array<string, mixed> */
    private function emptyGroup(string $key, ?string $host, ?DigitalAsset $existing): array
    {
        return [
            'key' => $key,
            'host' => $host,
            'existing' => $existing,
            'members' => [],
        ];
    }

    /** Business Profile title, then GA4 / Ads / Meta name, then the domain root. */
    private function suggestedName(array $group): string
    {
        if ($group['existing'] instanceof DigitalAsset) {
            return (string) $group['existing']->brand?->name;
        }
        foreach (['google_business_profile', 'ga4', 'google_ads', 'meta_ads'] as $type) {
            foreach ($group['members'] as [$resource]) {
                if ($resource->resource_type === $type && filled($resource->display_name)) {
                    return $this->displayName($resource);
                }
            }
        }

        return mb_convert_case(BrandSetupMatcher::domainRoot((string) $group['host']), MB_CASE_TITLE);
    }

    /** @return array<string, mixed> */
    private function present(array $group): array
    {
        $existing = $group['existing'];
        $seen = [];

        return [
            'key' => $group['key'],
            'host' => $group['host'],
            'suggested_brand' => (string) $group['suggested_brand'],
            'existing_brand_id' => $existing instanceof DigitalAsset ? (int) $existing->brand_id : null,
            'existing_brand' => $existing instanceof DigitalAsset ? (string) $existing->brand?->name : null,
            'existing_customer_id' => $existing instanceof DigitalAsset ? (int) $existing->brand?->customer_id : null,
            'resources' => collect($group['members'])->map(function (array $member) use (&$seen): array {
                [$resource, $reason] = $member;
                $type = (string) $resource->resource_type;
                // One Search Console / GA4 property per website: only the first is pre-selected.
                $first = in_array($type, ['search_console', 'ga4'], true) ? ! isset($seen[$type]) : true;
                $seen[$type] = true;

                return [
                    'id' => (int) $resource->id,
                    'type' => $type,
                    'type_label' => self::TYPE_LABELS[$type] ?? $type,
                    'label' => $this->displayName($resource),
                    'external_id' => (string) $resource->external_id,
                    'target' => in_array($type, ['search_console', 'ga4'], true) ? 'website' : 'new:'.$type,
                    'reason' => $reason,
                    'selected' => $first,
                ];
            })->values()->all(),
        ];
    }
}
