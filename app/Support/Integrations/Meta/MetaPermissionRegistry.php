<?php

namespace App\Support\Integrations\Meta;

/**
 * Canonical Meta permission registry for the frozen Meta Ads capability.
 *
 * Verified against Meta Facebook Login for Business / Marketing API docs
 * (verification date: 2026-08-13). Read-only product — does not request ads_management.
 */
final class MetaPermissionRegistry
{
    public const string ADS_READ = 'ads_read';

    public const string BUSINESS_MANAGEMENT = 'business_management';

    /**
     * Minimum permissions for Business + Ad Account inventory and future read Insights.
     *
     * @return list<string>
     */
    public static function requiredForMetaAds(): array
    {
        return [
            self::BUSINESS_MANAGEMENT,
            self::ADS_READ,
        ];
    }

    /**
     * @return list<string>
     */
    public static function forBusinessDiscovery(): array
    {
        return [self::BUSINESS_MANAGEMENT];
    }

    /**
     * @return list<string>
     */
    public static function forAdAccountDiscovery(): array
    {
        return [self::BUSINESS_MANAGEMENT, self::ADS_READ];
    }

    /**
     * @return list<string>
     */
    public static function forFutureCollection(): array
    {
        return [self::ADS_READ];
    }

    /**
     * Permissions that must never be requested by Prompt 22.
     *
     * @return list<string>
     */
    public static function forbiddenWriteOrUnrelated(): array
    {
        return [
            'ads_management',
            'pages_manage_posts',
            'pages_messaging',
            'instagram_content_publish',
            'leads_retrieval',
            'whatsapp_business_messaging',
            'catalog_management',
        ];
    }

    /**
     * @param  list<string>|null  $permissions
     * @return list<string>
     */
    public static function normalize(?array $permissions): array
    {
        if ($permissions === null) {
            return [];
        }

        $out = [];
        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                continue;
            }
            $value = trim($permission);
            if ($value === '') {
                continue;
            }
            $out[] = $value;
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param  list<string>  $granted
     * @param  list<string>  $required
     * @return list<string>
     */
    public static function missing(array $granted, array $required): array
    {
        $granted = self::normalize($granted);
        $required = self::normalize($required);

        return array_values(array_diff($required, $granted));
    }

    /**
     * @param  list<string>  $granted
     * @param  list<string>  $required
     */
    public static function covers(array $granted, array $required): bool
    {
        return self::missing($granted, $required) === [];
    }
}
