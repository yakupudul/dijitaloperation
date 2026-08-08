<?php

namespace App\Support\Integrations;

/**
 * Classifies existing CoreConnection types for the transitional dual model.
 *
 * Asset-scoped credentials remain on CoreConnection.
 * Provider-level types are transitional until migrated to Integration + Binding.
 */
final class ConnectionScope
{
    /**
     * Site-specific credentials that stay on CoreConnection.
     *
     * @return list<string>
     */
    public static function assetScopedTypes(): array
    {
        return [
            'wordpress',
        ];
    }

    /**
     * Provider-oriented connection types historically stored per Digital Asset.
     * These should eventually use agency Integration + External Resource Binding.
     *
     * @return list<string>
     */
    public static function providerLevelTypes(): array
    {
        return [
            'ga4',
            'search_console',
            'pagespeed',
            'dataforseo',
            'google_business_profile_api',
            'google_ads_api',
            'meta_ads_api',
            'instagram_api',
        ];
    }

    public static function isAssetScoped(string $type): bool
    {
        return in_array($type, self::assetScopedTypes(), true);
    }

    public static function isProviderLevel(string $type): bool
    {
        return in_array($type, self::providerLevelTypes(), true);
    }
}
