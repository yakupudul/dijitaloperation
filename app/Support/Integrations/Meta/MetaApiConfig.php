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

    /**
     * Deployment-level Meta App ID (not a tenant authorization credential).
     */
    public static function appId(): ?string
    {
        $value = trim((string) config('moxdop.meta.app_id', ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Deployment-level Meta App Secret (never UI-visible; not copied into tenant credentials).
     */
    public static function appSecret(): ?string
    {
        $value = trim((string) config('moxdop.meta.app_secret', ''));

        return $value !== '' ? $value : null;
    }

    public static function isApplicationConfigured(): bool
    {
        return self::appId() !== null && self::appSecret() !== null;
    }

    /**
     * Facebook Login for Business configuration ID from Meta App Dashboard.
     * Required for production Login-for-Business dialog; optional for local scope fallback.
     */
    public static function loginConfigurationId(): ?string
    {
        $value = trim((string) config('moxdop.meta.login_configuration_id', ''));

        return $value !== '' ? $value : null;
    }

    /**
     * App access token form used only for server-side debug_token (never UI/queue).
     */
    public static function appAccessToken(): ?string
    {
        $appId = self::appId();
        $secret = self::appSecret();
        if ($appId === null || $secret === null) {
            return null;
        }

        return $appId.'|'.$secret;
    }

    /**
     * Official appsecret_proof for server-side Graph calls.
     *
     * @see https://developers.facebook.com/docs/graph-api/securing-requests
     */
    public static function appSecretProof(string $accessToken): ?string
    {
        $secret = self::appSecret();
        if ($secret === null || $accessToken === '') {
            return null;
        }

        return hash_hmac('sha256', $accessToken, $secret);
    }

    public static function dialogBaseUrl(): string
    {
        return 'https://www.facebook.com/'.self::apiVersion().'/dialog/oauth';
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
        return MetaPermissionRegistry::requiredForMetaAds();
    }

    /**
     * Safe Ad Account fields for discovery (official AdAccount node).
     */
    public static function adAccountFields(): string
    {
        return 'account_id,id,name,account_status,currency,timezone_name,business{id,name}';
    }
}
