<?php

namespace App\Support\Integrations\Meta;

/**
 * Central Meta Graph / Marketing API configuration.
 * Official Marketing API current version (verified 2026-08): v26.0
 * Docs: https://developers.facebook.com/docs/marketing-api/overview/versioning/
 * Also: https://developers.facebook.com/documentation/ads-commerce/marketing-api/overview/versioning
 */
final class MetaApiConfig
{
    public const string PROVIDER = 'meta';

    /** Canonical Marketing API version — do not scatter version strings elsewhere. */
    public const string DEFAULT_API_VERSION = 'v26.0';

    /** Official Graph host — never operator-configurable (SSRF prevention). */
    public const string GRAPH_HOST = 'graph.facebook.com';

    public const string GRAPH_SCHEME = 'https';

    public static function apiVersion(): string
    {
        $version = (string) config('moxdop.meta.api_version', self::DEFAULT_API_VERSION);
        $version = trim($version);
        if ($version === '' || ! preg_match('/^v\d+\.\d+$/', $version)) {
            return self::DEFAULT_API_VERSION;
        }

        return $version;
    }

    public static function graphBaseUrl(): string
    {
        return self::GRAPH_SCHEME.'://'.self::GRAPH_HOST.'/'.self::apiVersion();
    }

    public static function timeoutSeconds(): int
    {
        return max(5, (int) config('moxdop.meta.timeout', 20));
    }

    public static function maxPaginationPages(): int
    {
        return max(1, min(50, (int) config('moxdop.meta.max_pagination_pages', 20)));
    }

    /**
     * Least-privilege read permissions for Ad Account discovery.
     *
     * @return list<string>
     */
    public static function requiredReadPermissions(): array
    {
        return [
            'ads_read',
            'business_management',
        ];
    }

    /**
     * Safe Ad Account fields for discovery (official AdAccount node).
     */
    public static function adAccountFields(): string
    {
        return 'account_id,id,name,account_status,currency,timezone_name,business{id,name}';
    }
}
