<?php

namespace App\Support\Integrations\Google;

/**
 * Deployment/env-level Google settings (redirect URI, Ads API version, optional env fallbacks).
 *
 * Application Client ID/Secret/developer token resolution for a specific Integration
 * belongs in GoogleCredentialResolver (DB provider credential first, then env fallback).
 */
final class GoogleOAuthConfig
{
    public static function envClientId(): ?string
    {
        $value = config('moxdop.google.client_id');

        return filled($value) ? (string) $value : null;
    }

    public static function envClientSecret(): ?string
    {
        $value = config('moxdop.google.client_secret');

        return filled($value) ? (string) $value : null;
    }

    public static function envDeveloperToken(): ?string
    {
        $value = config('moxdop.google.developer_token');

        return filled($value) ? (string) $value : null;
    }

    /**
     * @deprecated Prefer GoogleCredentialResolver with a CoreIntegration.
     */
    public static function clientId(): ?string
    {
        return self::envClientId();
    }

    /**
     * @deprecated Prefer GoogleCredentialResolver with a CoreIntegration.
     */
    public static function clientSecret(): ?string
    {
        return self::envClientSecret();
    }

    /**
     * @deprecated Prefer GoogleCredentialResolver with a CoreIntegration.
     */
    public static function developerToken(): ?string
    {
        return self::envDeveloperToken();
    }

    public static function redirectUri(): string
    {
        return (string) config('moxdop.google.redirect_uri');
    }

    /**
     * Route-derived callback URL for display / Cloud Console paste.
     */
    public static function derivedRedirectUri(): string
    {
        try {
            return route('integrations.google.callback', absolute: true);
        } catch (\Throwable) {
            return rtrim((string) config('app.url'), '/').'/integrations/google/callback';
        }
    }

    public static function redirectUriMismatch(): bool
    {
        return rtrim(self::redirectUri(), '/') !== rtrim(self::derivedRedirectUri(), '/');
    }

    /**
     * Configured Google Ads API major version (e.g. v25).
     * Source of truth: GOOGLE_ADS_API_VERSION / config('moxdop.google.ads_api_version').
     */
    public static function adsApiVersion(): string
    {
        $version = trim((string) config('moxdop.google.ads_api_version', 'v25'));

        if ($version === '') {
            return 'v25';
        }

        if (! str_starts_with($version, 'v')) {
            $version = 'v'.$version;
        }

        return $version;
    }

    /**
     * Absolute REST URL under the configured Google Ads API version.
     */
    public static function adsApiUrl(string $path): string
    {
        return 'https://googleads.googleapis.com/'.self::adsApiVersion().'/'.ltrim($path, '/');
    }

    /**
     * Env-only check (no Integration context). Prefer GoogleCredentialResolver::isAppConfigured().
     */
    public static function isConfigured(): bool
    {
        return self::envClientId() !== null && self::envClientSecret() !== null;
    }

    /**
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        $missing = [];

        if (self::envClientId() === null) {
            $missing[] = 'GOOGLE_CLIENT_ID';
        }

        if (self::envClientSecret() === null) {
            $missing[] = 'GOOGLE_CLIENT_SECRET';
        }

        return $missing;
    }
}
