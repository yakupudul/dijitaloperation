<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\DigitalAsset;

/** Host helpers of the site screen: normalized hosts, registrable domain, list membership, a brand's own domains. */
final class SiteDomains
{
    /** Second-level labels under a two-letter country code (example.com.tr, example.co.uk). */
    private const array SECOND_LEVEL = ['com', 'net', 'org', 'gen', 'av', 'dr', 'edu', 'gov', 'k12', 'bel', 'pol', 'tsk', 'web', 'info', 'biz', 'name', 'tv', 'co', 'ac', 'bbs', 'tel'];

    /** Lower case host without "www." from a URL or a bare host; null when there is none. */
    public static function host(?string $urlOrHost): ?string
    {
        $value = trim((string) $urlOrHost);
        if ($value === '') {
            return null;
        }
        $host = str_contains($value, '://') ? parse_url($value, PHP_URL_HOST) : parse_url('https://'.ltrim($value, '/'), PHP_URL_HOST);
        if (! is_string($host) || $host === '' || ! str_contains($host, '.')) {
            return null;
        }
        $host = mb_strtolower(rtrim($host, '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** example.com for shop.example.com; example.com.tr for www.example.com.tr. */
    public static function registrable(string $host): string
    {
        $labels = explode('.', mb_strtolower(trim($host, '.')));
        $count = count($labels);
        if ($count <= 2) {
            return implode('.', $labels);
        }
        $take = strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], self::SECOND_LEVEL, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    /** The host equals a listed domain or is its subdomain (tr.wikipedia.org ∈ wikipedia.org). */
    public static function inList(string $host, array $list): bool
    {
        foreach ($list as $domain) {
            $domain = mb_strtolower(trim((string) $domain));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registrable domains of the brand's websites.
     *
     * @return list<string>
     */
    public static function ownDomains(Brand $brand): array
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get(['domain', 'primary_url'])
            ->flatMap(fn (DigitalAsset $site): array => array_filter([self::host($site->domain), self::host($site->primary_url)]))
            ->map(fn (string $host): string => self::registrable($host))->unique()->values()->all();
    }

    /** @param  list<string>  $own */
    public static function isOwn(string $host, array $own): bool
    {
        return in_array(self::registrable($host), $own, true);
    }
}
