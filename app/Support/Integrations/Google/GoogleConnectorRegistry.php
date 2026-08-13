<?php

namespace App\Support\Integrations\Google;

use App\Support\Integrations\ProviderRegistry;

/**
 * Canonical Google Connector definitions (provider capabilities).
 *
 * Connector ≠ Integration credential ≠ ExternalResource ≠ Collector.
 * Frozen UI connector slugs (ga4, gsc, google-ads, gbp) map here.
 */
final class GoogleConnectorRegistry
{
    public const string PROVIDER = ProviderRegistry::GOOGLE;

    /**
     * @return array<string, array{
     *     id: string,
     *     provider: string,
     *     label: string,
     *     capability: string,
     *     resource_type: string,
     *     resource_type_label: string,
     *     ui_slug: string,
     *     visual_type: string,
     *     digital_asset_types: list<string>,
     *     discovery: string,
     *     binding: string,
     *     collection: string,
     *     authorization_dependency: string,
     *     next_prompt: string,
     *     gbp_gated: bool
     * }>
     */
    public static function all(): array
    {
        return [
            'ga4' => [
                'id' => 'ga4',
                'provider' => self::PROVIDER,
                'label' => 'Google Analytics',
                'capability' => 'ga4',
                'resource_type' => GoogleResourceType::GA4_PROPERTY,
                'resource_type_label' => GoogleResourceType::label(GoogleResourceType::GA4_PROPERTY),
                'ui_slug' => 'ga4',
                'visual_type' => 'ga4',
                'digital_asset_types' => ['website', 'ga4'],
                'discovery' => 'PARTIAL',
                'binding' => 'PARTIAL',
                'collection' => 'PARTIAL_LEGACY_BOUND',
                'authorization_dependency' => 'google_oauth_authorization',
                'next_prompt' => 'Prompt 15 / 16 / 18',
                'gbp_gated' => false,
            ],
            'search_console' => [
                'id' => 'search_console',
                'provider' => self::PROVIDER,
                'label' => 'Search Console',
                'capability' => 'search_console',
                'resource_type' => GoogleResourceType::GSC_PROPERTY,
                'resource_type_label' => GoogleResourceType::label(GoogleResourceType::GSC_PROPERTY),
                'ui_slug' => 'gsc',
                'visual_type' => 'gsc',
                'digital_asset_types' => ['website', 'gsc', 'search_console'],
                'discovery' => 'PARTIAL',
                'binding' => 'PARTIAL',
                'collection' => 'PARTIAL_LEGACY_BOUND',
                'authorization_dependency' => 'google_oauth_authorization',
                'next_prompt' => 'Prompt 15 / 16 / 17',
                'gbp_gated' => false,
            ],
            'google_ads' => [
                'id' => 'google_ads',
                'provider' => self::PROVIDER,
                'label' => 'Google Ads',
                'capability' => 'google_ads',
                'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
                'resource_type_label' => GoogleResourceType::label(GoogleResourceType::GOOGLE_ADS_CUSTOMER),
                'ui_slug' => 'google-ads',
                'visual_type' => 'google_ads',
                'digital_asset_types' => ['google_ads'],
                'discovery' => 'PARTIAL',
                'binding' => 'PARTIAL',
                'collection' => 'PARTIAL_LEGACY_BOUND',
                'authorization_dependency' => 'google_oauth_authorization',
                'next_prompt' => 'Prompt 15 / 16 / 19',
                'gbp_gated' => false,
            ],
            'google_business_profile' => [
                'id' => 'google_business_profile',
                'provider' => self::PROVIDER,
                'label' => 'Google Business Profile',
                'capability' => 'google_business_profile',
                'resource_type' => GoogleResourceType::GBP_LOCATION,
                'resource_type_label' => GoogleResourceType::label(GoogleResourceType::GBP_LOCATION),
                'ui_slug' => 'gbp',
                'visual_type' => 'gbp',
                'digital_asset_types' => ['google_business_profile'],
                'discovery' => 'PARTIAL_GATED',
                'binding' => 'PARTIAL',
                'collection' => 'PARTIAL_LEGACY_BOUND',
                'authorization_dependency' => 'google_oauth_authorization_gbp_scope',
                'next_prompt' => 'Prompt 15 / 16 (GBP gated by product config)',
                'gbp_gated' => true,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $connectorId): ?array
    {
        return self::all()[$connectorId] ?? null;
    }

    public static function byUiSlug(string $slug): ?array
    {
        foreach (self::all() as $connector) {
            if ($connector['ui_slug'] === $slug) {
                return $connector;
            }
        }

        return null;
    }

    public static function byCapability(string $capability): ?array
    {
        return self::get($capability);
    }

    public static function byResourceType(string $resourceType): ?array
    {
        foreach (self::all() as $connector) {
            if ($connector['resource_type'] === $resourceType) {
                return $connector;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function capabilities(): array
    {
        return ProviderRegistry::capabilities(self::PROVIDER);
    }

    /**
     * One Google Integration authorization credential covers all Connectors.
     */
    public static function sharesAuthorizationCredential(): bool
    {
        return true;
    }
}
