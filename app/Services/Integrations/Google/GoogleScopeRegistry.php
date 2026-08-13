<?php

namespace App\Services\Integrations\Google;

use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\ProviderRegistry;

/**
 * Canonical Connector → OAuth scope mapping.
 *
 * Verified against official Google docs (2026-08-13):
 * - Search Console: webmasters.readonly (read-only)
 * - GA4: analytics.readonly (read-only)
 * - Google Ads: adwords only (no read-only OAuth scope; MoxDOP still forbids writes)
 * - GBP: business.manage (manage-level provider scope; MoxDOP forbids writes)
 *
 * Sources:
 * https://developers.google.com/webmaster-tools/v1/how-tos/authorizing
 * https://developers.google.com/identity/protocols/oauth2/scopes
 * https://developers.google.com/google-ads/api/docs/oauth/overview
 */
final class GoogleScopeRegistry
{
    public const string CAPABILITY_GA4 = 'ga4';

    public const string CAPABILITY_SEARCH_CONSOLE = 'search_console';

    public const string CAPABILITY_GOOGLE_ADS = 'google_ads';

    public const string CAPABILITY_GBP = 'google_business_profile';

    /**
     * @return array<string, array{
     *     capability: string,
     *     scopes: list<string>,
     *     read_only_available: bool,
     *     provider_broader_than_product: bool,
     *     sensitive_or_restricted: bool,
     *     verification_impact: string,
     *     moxdop_operations: string,
     *     official_source: string
     * }>
     */
    public function all(): array
    {
        return [
            self::CAPABILITY_SEARCH_CONSOLE => [
                'capability' => self::CAPABILITY_SEARCH_CONSOLE,
                'scopes' => [GoogleScopes::SEARCH_CONSOLE_READONLY],
                'read_only_available' => true,
                'provider_broader_than_product' => false,
                'sensitive_or_restricted' => true,
                'verification_impact' => 'Sensitive/restricted scope review may apply for production OAuth verification',
                'moxdop_operations' => 'read',
                'official_source' => 'https://developers.google.com/webmaster-tools/v1/how-tos/authorizing',
            ],
            self::CAPABILITY_GA4 => [
                'capability' => self::CAPABILITY_GA4,
                'scopes' => [GoogleScopes::ANALYTICS_READONLY],
                'read_only_available' => true,
                'provider_broader_than_product' => false,
                'sensitive_or_restricted' => true,
                'verification_impact' => 'Sensitive scope verification may apply',
                'moxdop_operations' => 'read',
                'official_source' => 'https://developers.google.com/identity/protocols/oauth2/scopes',
            ],
            self::CAPABILITY_GOOGLE_ADS => [
                'capability' => self::CAPABILITY_GOOGLE_ADS,
                'scopes' => [GoogleScopes::ADWORDS],
                'read_only_available' => false,
                'provider_broader_than_product' => true,
                'sensitive_or_restricted' => true,
                'verification_impact' => 'Restricted; Google Ads API + developer token approval separate from OAuth verification',
                'moxdop_operations' => 'read',
                'official_source' => 'https://developers.google.com/google-ads/api/docs/oauth/overview',
            ],
            self::CAPABILITY_GBP => [
                'capability' => self::CAPABILITY_GBP,
                'scopes' => [GoogleScopes::BUSINESS_MANAGE],
                'read_only_available' => false,
                'provider_broader_than_product' => true,
                'sensitive_or_restricted' => true,
                'verification_impact' => 'business.manage is manage-level; GBP API access often needs separate approval',
                'moxdop_operations' => 'read',
                'official_source' => 'https://developers.google.com/my-business/content/basic-setup',
            ],
        ];
    }

    /**
     * Default connectors requested on first Connect (minimum product set).
     *
     * @return list<string>
     */
    public function defaultCapabilities(): array
    {
        $caps = [
            self::CAPABILITY_SEARCH_CONSOLE,
            self::CAPABILITY_GA4,
            self::CAPABILITY_GOOGLE_ADS,
        ];

        if ((bool) config('moxdop.google.include_gbp_scope', false)) {
            $caps[] = self::CAPABILITY_GBP;
        }

        return $caps;
    }

    /**
     * @param  list<string>|null  $capabilities
     * @return list<string>
     */
    public function scopesForCapabilities(?array $capabilities = null): array
    {
        $capabilities ??= $this->defaultCapabilities();
        $scopes = [];

        foreach ($capabilities as $capability) {
            $meta = $this->all()[$capability] ?? null;
            if ($meta === null) {
                continue;
            }
            foreach ($meta['scopes'] as $scope) {
                $scopes[] = $scope;
            }
        }

        return $this->normalize($scopes);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function normalize(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            if (! is_string($scope)) {
                continue;
            }
            $scope = trim($scope);
            if ($scope === '') {
                continue;
            }
            $normalized[] = $scope;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  list<string>|string|null  $raw
     * @return list<string>
     */
    public function parseGranted(array|string|null $raw): array
    {
        if (is_array($raw)) {
            return $this->normalize($raw);
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return $this->normalize(preg_split('/\s+/', trim($raw)) ?: []);
    }

    /**
     * @param  list<string>  $granted
     * @param  list<string>  $required
     * @return list<string>
     */
    public function missing(array $granted, array $required): array
    {
        $granted = $this->normalize($granted);
        $required = $this->normalize($required);

        return array_values(array_diff($required, $granted));
    }

    public function capabilityForScope(string $scope): ?string
    {
        foreach ($this->all() as $capability => $meta) {
            if (in_array($scope, $meta['scopes'], true)) {
                return $capability;
            }
        }

        return null;
    }

    public function isIdentityScope(string $scope): bool
    {
        return in_array($scope, [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ], true);
    }

    /**
     * @return list<string>
     */
    public function providerCapabilities(): array
    {
        return ProviderRegistry::capabilities(ProviderRegistry::GOOGLE);
    }
}
