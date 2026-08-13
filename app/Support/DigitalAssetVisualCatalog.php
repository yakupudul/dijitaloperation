<?php

namespace App\Support;

/**
 * Central Demo/product visual identity for Digital Asset types.
 * Provider marks are local SVG assets — no remote hotlinking.
 */
final class DigitalAssetVisualCatalog
{
    /**
     * @return array{
     *     type: string,
     *     label: string,
     *     mark: string,
     *     asset_path: string,
     *     fallback_initials: string,
     *     container: string,
     *     a11y: string
     * }
     */
    public static function forType(string $type): array
    {
        $normalized = self::normalizeType($type);

        return match ($normalized) {
            'website' => self::entry('website', 'Website', 'website-atlas', 'WEB', 'bg-white dark:bg-white/95', 'Website'),
            'google_ads' => self::entry('google_ads', 'Google Ads', 'google-ads', 'Ads', 'bg-white dark:bg-white/95', 'Google Ads'),
            'meta_ads' => self::entry('meta_ads', 'Meta Ads', 'meta', 'Meta', 'bg-white dark:bg-white/95', 'Meta Ads'),
            'gbp' => self::entry('gbp', 'Google Business Profile', 'gbp', 'GBP', 'bg-white dark:bg-white/95', 'Google Business Profile'),
            'ga4' => self::entry('ga4', 'Google Analytics', 'ga4', 'GA4', 'bg-white dark:bg-white/95', 'Google Analytics'),
            'gsc' => self::entry('gsc', 'Search Console', 'gsc', 'GSC', 'bg-white dark:bg-white/95', 'Search Console'),
            'instagram' => self::entry('instagram', 'Instagram', 'instagram', 'IG', 'bg-white dark:bg-white/95', 'Instagram'),
            'domain' => self::entry('domain', 'Domain', 'globe', 'DOM', 'bg-slate-100 dark:bg-white/10', 'Domain'),
            'hosting' => self::entry('hosting', 'Hosting', 'server', 'HST', 'bg-slate-100 dark:bg-white/10', 'Hosting'),
            default => self::entry($normalized, ucfirst(str_replace('_', ' ', $normalized)), 'globe', 'AST', 'bg-slate-100 dark:bg-white/10', 'Digital Asset'),
        };
    }

    /**
     * Resolve visual identity for a concrete asset row (Website prefers Brand mark).
     *
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public static function resolve(array $asset): array
    {
        $type = (string) ($asset['type'] ?? 'other');
        $base = self::forType($type);

        if (self::normalizeType($type) === 'website') {
            $base['mark'] = 'website-atlas';
            $base['asset_path'] = self::markPath('website-atlas');
            $base['a11y'] = (string) ($asset['name'] ?? 'Website');
            $base['source'] = 'brand_logo_fixture';
        } else {
            $base['source'] = 'provider_mark';
        }

        $base['asset_id'] = $asset['id'] ?? null;
        $base['asset_name'] = $asset['name'] ?? $base['label'];

        return $base;
    }

    public static function markPath(string $mark): string
    {
        return asset('images/digital-assets/'.$mark.'.svg');
    }

    public static function normalizeType(string $type): string
    {
        return match (strtolower($type)) {
            'analytics', 'google_analytics' => 'ga4',
            'gads' => 'google_ads',
            'meta' => 'meta_ads',
            'google_business_profile' => 'gbp',
            default => strtolower($type),
        };
    }

    /**
     * Brand logo path used as Website visual identity in Demo Mode.
     */
    public static function brandLogoPath(?string $brandId = null): string
    {
        unset($brandId);

        return self::markPath('website-atlas');
    }

    /**
     * @return array{type: string, label: string, mark: string, asset_path: string, fallback_initials: string, container: string, a11y: string}
     */
    private static function entry(
        string $type,
        string $label,
        string $mark,
        string $initials,
        string $container,
        string $a11y,
    ): array {
        return [
            'type' => $type,
            'label' => $label,
            'mark' => $mark,
            'asset_path' => self::markPath($mark),
            'fallback_initials' => $initials,
            'container' => $container,
            'a11y' => $a11y,
        ];
    }
}
