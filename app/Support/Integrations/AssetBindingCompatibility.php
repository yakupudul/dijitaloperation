<?php

namespace App\Support\Integrations;

use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;

/**
 * Server-side compatibility between Digital Asset types and External Resource capabilities.
 */
final class AssetBindingCompatibility
{
    /**
     * @return list<string>
     */
    public static function capabilitiesForAssetType(string $assetType): array
    {
        return match ($assetType) {
            'website' => ['search_console', 'ga4'],
            'ga4', 'google_analytics', 'analytics' => ['ga4'],
            'gsc', 'search_console', 'google_search_console' => ['search_console'],
            'google_ads' => ['google_ads'],
            'google_business_profile', 'gbp' => ['google_business_profile'],
            'meta_ads' => ['meta_ads'],
            default => [],
        };
    }

    public static function isCompatible(DigitalAsset $asset, CoreExternalResource $resource): bool
    {
        $allowed = self::capabilitiesForAssetType((string) $asset->type);

        return in_array($resource->resource_type, $allowed, true);
    }
}
