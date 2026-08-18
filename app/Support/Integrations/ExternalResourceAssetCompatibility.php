<?php

namespace App\Support\Integrations;

use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;

/**
 * Canonical ExternalResource type → DigitalAsset type mapping for selection/binding.
 *
 * Does not use display-name heuristics.
 */
final class ExternalResourceAssetCompatibility
{
    /**
     * Preferred DigitalAsset.type when creating a new asset for a resource type.
     */
    public static function preferredAssetType(string $resourceType): ?string
    {
        return match ($resourceType) {
            'ga4' => 'ga4',
            'search_console' => 'gsc',
            'google_ads' => 'google_ads',
            'google_business_profile' => 'google_business_profile',
            'meta_ads' => 'meta_ads',
            // Meta Business is a provider container — never auto DigitalAsset.
            'meta_business' => null,
            default => null,
        };
    }

    /**
     * DigitalAsset types that may receive a Binding for this resource type.
     *
     * @return list<string>
     */
    public static function compatibleAssetTypes(string $resourceType): array
    {
        return match ($resourceType) {
            'ga4' => ['ga4', 'google_analytics', 'analytics', 'website'],
            'search_console' => ['gsc', 'search_console', 'google_search_console', 'website'],
            'google_ads' => ['google_ads'],
            'google_business_profile' => ['google_business_profile', 'gbp'],
            'meta_ads' => ['meta_ads'],
            // META_BUSINESS is not bindable.
            'meta_business' => [],
            default => [],
        };
    }

    public static function canBindResourceToAssetType(string $resourceType, string $assetType): bool
    {
        return in_array($assetType, self::compatibleAssetTypes($resourceType), true);
    }

    public static function isCompatible(DigitalAsset $asset, CoreExternalResource $resource): bool
    {
        return AssetBindingCompatibility::isCompatible($asset, $resource)
            && self::canBindResourceToAssetType((string) $resource->resource_type, (string) $asset->type);
    }
}
