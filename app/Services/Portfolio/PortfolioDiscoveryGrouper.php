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

    /** Social / link-in-bio / map hosts: a Business Profile "website" on these says nothing about the brand. */
    private const array SHARED_HOSTS = [
        'instagram.com', 'facebook.com', 'fb.com', 'm.facebook.com', 'business.facebook.com', 'twitter.com', 'x.com',
        'youtube.com', 'tiktok.com', 'linkedin.com', 'linktr.ee', 'wa.me', 'api.whatsapp.com', 'whatsapp.com',
        'maps.google.com', 'google.com', 'g.page', 'goo.gl', 'maps.app.goo.gl', 'sites.google.com', 'bit.ly',
    ];

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
            // The account's own name counts; the owning business / manager name only proposes (not pre-selected),
            // because one business often owns accounts of several brands.
            $own = $this->displayName($resource);
            $parent = $this->parentName($resource);
            $best = null;
            $bestScore = 0.0;
            $viaParent = false;
            foreach ($groups as $key => $group) {
                $ownScore = BrandSetupMatcher::nameScore($own, (string) $group['suggested_brand'], (string) ($group['host'] ?? ''));
                $parentScore = $parent !== '' ? BrandSetupMatcher::nameScore($parent, (string) $group['suggested_brand'], (string) ($group['host'] ?? '')) : 0.0;
                $score = max($ownScore, $parentScore);
                if ($score > $bestScore) {
                    [$best, $bestScore, $viaParent] = [$key, $score, $ownScore < self::NAME_MATCH];
                }
            }
            if ($best !== null && $bestScore >= self::NAME_MATCH) {
                $groups[$best]['members'][] = $viaParent
                    ? [$resource, 'Hesabın bağlı olduğu işletme ("'.$parent.'") markaya benziyor; hesap adı benzemiyor, kontrol et.', false]
                    : [$resource, sprintf('Hesap adı markaya benziyor (%%%d).', (int) round($bestScore * 100))];

                continue;
            }
            $key = 'name:'.str_replace(' ', '', SeoText::fold($this->displayName($resource)));
            $groups[$key] ??= $this->emptyGroup($key, null, null) + ['suggested_brand' => self::cleanName($this->displayName($resource), '')];
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

        return $host !== '' && ! in_array($host, self::SHARED_HOSTS, true) ? $host : null;
    }

    private function searchConsoleUrl(string $siteUrl): string
    {
        return str_starts_with($siteUrl, 'sc-domain:') ? substr($siteUrl, strlen('sc-domain:')) : $siteUrl;
    }

    /** Owning business / manager / property-account name (not the account's own name). */
    private function parentName(CoreExternalResource $resource): string
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $own = SeoText::fold($this->displayName($resource));
        foreach (['business_name', 'account_display_name', 'descriptive_name'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '' && SeoText::fold($value) !== $own) {
                return $value;
            }
        }

        return '';
    }

    /**
     * A readable brand name from an account title: drops "- GA4", "Reklam Hesabı", URLs and trailing dashes; of a
     * "A | B | C" title keeps the part closest to the domain (else the first).
     */
    public static function cleanName(string $name, string $host): string
    {
        $name = trim(preg_replace('#https?://\S+#i', '', $name) ?? $name);
        $name = trim(preg_replace('/\s*[-–]?\s*\bGA4\b\s*$/iu', '', $name) ?? $name);
        $name = trim(preg_replace('/\s*(reklam\s+hesab[ıi]|ad\s+account)\s*$/iu', '', $name) ?? $name);
        $name = trim($name, " \t-–|:");
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s+\|\s+/u', $name) ?: [$name])));
        if (count($parts) > 1) {
            $best = $parts[0];
            $bestScore = 0.0;
            foreach ($parts as $part) {
                $score = $host !== '' ? BrandSetupMatcher::nameScore($part, '', $host) : 0.0;
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$part, $score];
                }
            }
            $name = $best;
        }
        if (mb_strlen($name) > 80) {
            $name = rtrim(mb_substr($name, 0, 80));
        }

        return $name !== '' ? $name : ($host !== '' ? mb_convert_case(BrandSetupMatcher::domainRoot($host), MB_CASE_TITLE) : '');
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
                    $name = self::cleanName($this->displayName($resource), (string) $group['host']);
                    // Numeric account names ("936764867674279") are not brand names.
                    if ($name !== '' && ! ctype_digit(str_replace(['-', ' '], '', $name))) {
                        return $name;
                    }
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
                $confident = $member[2] ?? true;
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
                    'selected' => $first && $confident,
                ];
            })->values()->all(),
        ];
    }
}
