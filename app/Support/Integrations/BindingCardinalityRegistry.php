<?php

namespace App\Support\Integrations;

/**
 * Centralized Binding cardinality rules (Prompt 16).
 *
 * Enforced in ConfirmGoogleResourceBindingService (+ existing DB uniques).
 */
final class BindingCardinalityRegistry
{
    /**
     * @return array{
     *     resource_type: string,
     *     preferred_asset_type: string|null,
     *     max_active_resources_per_asset: int,
     *     max_active_assets_per_resource: int,
     *     managers_selectable: bool,
     *     notes: string
     * }
     */
    public static function forResourceType(string $resourceType): array
    {
        return match ($resourceType) {
            'ga4' => [
                'resource_type' => 'ga4',
                'preferred_asset_type' => 'ga4',
                'max_active_resources_per_asset' => 1,
                'max_active_assets_per_resource' => 1,
                'managers_selectable' => false,
                'notes' => 'One GA4 Property ↔ one GA4 (or Website measurement) Binding slot via capability uniqueness.',
            ],
            'search_console' => [
                'resource_type' => 'search_console',
                'preferred_asset_type' => 'gsc',
                'max_active_resources_per_asset' => 1,
                'max_active_assets_per_resource' => 1,
                'managers_selectable' => false,
                'notes' => 'One GSC Property ↔ one GSC/Website Binding for search_console capability.',
            ],
            'google_ads' => [
                'resource_type' => 'google_ads',
                'preferred_asset_type' => 'google_ads',
                'max_active_resources_per_asset' => 1,
                'max_active_assets_per_resource' => 1,
                'managers_selectable' => false,
                'notes' => 'Bind client customers only; MCC/manager accounts are hierarchy context.',
            ],
            'google_business_profile' => [
                'resource_type' => 'google_business_profile',
                'preferred_asset_type' => 'google_business_profile',
                'max_active_resources_per_asset' => 1,
                'max_active_assets_per_resource' => 1,
                'managers_selectable' => false,
                'notes' => 'GBP Location → google_business_profile DigitalAsset; account containers are not bind targets.',
            ],
            'meta_ads' => [
                'resource_type' => 'meta_ads',
                'preferred_asset_type' => 'meta_ads',
                'max_active_resources_per_asset' => 1,
                'max_active_assets_per_resource' => 1,
                'managers_selectable' => false,
                'notes' => 'One Meta Ads DigitalAsset ↔ one active META_AD_ACCOUNT. META_BUSINESS is never a Binding root. Shared Ad Account across assets is not supported.',
            ],
            default => [
                'resource_type' => $resourceType,
                'preferred_asset_type' => ExternalResourceAssetCompatibility::preferredAssetType($resourceType),
                'max_active_resources_per_asset' => 1,
                'max_active_assets_per_resource' => 1,
                'managers_selectable' => false,
                'notes' => 'Default one-to-one active Binding.',
            ],
        };
    }
}
