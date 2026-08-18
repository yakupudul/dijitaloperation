<?php

namespace App\Support\Integrations\Google;

/**
 * Canonical Google ExternalResource type taxonomy.
 *
 * Stored `resource_type` values match ProviderRegistry capability IDs
 * (existing discovery upserts). Semantic labels describe the bindable entity.
 */
final class GoogleResourceType
{
    public const string GA4_PROPERTY = 'ga4';

    public const string GSC_PROPERTY = 'search_console';

    public const string GOOGLE_ADS_CUSTOMER = 'google_ads';

    public const string GBP_LOCATION = 'google_business_profile';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::GA4_PROPERTY,
            self::GSC_PROPERTY,
            self::GOOGLE_ADS_CUSTOMER,
            self::GBP_LOCATION,
        ];
    }

    public static function isValid(string $resourceType): bool
    {
        return in_array($resourceType, self::all(), true);
    }

    public static function label(string $resourceType): string
    {
        return match ($resourceType) {
            self::GA4_PROPERTY => 'GA4 Property',
            self::GSC_PROPERTY => 'Search Console Property',
            self::GOOGLE_ADS_CUSTOMER => 'Google Ads Customer',
            self::GBP_LOCATION => 'GBP Location',
            default => str($resourceType)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * Visual mark / demo asset type key for frozen UI chips.
     */
    public static function visualType(string $resourceType): string
    {
        return match ($resourceType) {
            self::GA4_PROPERTY => 'ga4',
            self::GSC_PROPERTY => 'gsc',
            self::GOOGLE_ADS_CUSTOMER => 'google_ads',
            self::GBP_LOCATION => 'gbp',
            default => 'website',
        };
    }
}
