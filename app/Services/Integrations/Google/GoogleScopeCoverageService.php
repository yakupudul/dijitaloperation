<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Support\Integrations\Google\GoogleAuthStatus;

/**
 * Answers Connector authorization coverage from persisted granted scopes.
 * No provider HTTP.
 */
final class GoogleScopeCoverageService
{
    public function __construct(
        private readonly GoogleScopeRegistry $registry = new GoogleScopeRegistry,
    ) {}

    /**
     * @return list<string>
     */
    public function grantedScopes(CoreIntegration $integration): array
    {
        $fromConfig = data_get($integration->config, 'granted_scopes');
        if (is_array($fromConfig) && $fromConfig !== []) {
            return $this->registry->parseGranted($fromConfig);
        }

        $payload = $integration->authorizationCredential?->encrypted_payload;
        if (is_array($payload)) {
            return $this->registry->parseGranted($payload['scope'] ?? null);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function requestedScopes(CoreIntegration $integration): array
    {
        return $this->registry->parseGranted(data_get($integration->config, 'requested_scopes'));
    }

    /**
     * @param  list<string>|null  $capabilities
     * @return list<string>
     */
    public function missingScopes(CoreIntegration $integration, ?array $capabilities = null): array
    {
        $required = $this->registry->scopesForCapabilities($capabilities);

        return $this->registry->missing($this->grantedScopes($integration), $required);
    }

    public function hasCapability(CoreIntegration $integration, string $capability): bool
    {
        $required = $this->registry->scopesForCapabilities([$capability]);
        if ($required === []) {
            return false;
        }

        return $this->missingScopes($integration, [$capability]) === [];
    }

    /**
     * @return list<array{
     *     capability: string,
     *     label: string,
     *     status: string,
     *     status_label: string,
     *     missing_scopes: list<string>
     * }>
     */
    public function connectorStatuses(CoreIntegration $integration): array
    {
        $auth = GoogleAuthStatus::for($integration);
        $out = [];

        foreach ($this->registry->defaultCapabilities() as $capability) {
            $meta = $this->registry->all()[$capability] ?? null;
            if ($meta === null) {
                continue;
            }

            $missing = $this->missingScopes($integration, [$capability]);
            $status = $this->statusFor($auth, $missing === []);

            $out[] = [
                'capability' => $capability,
                'label' => match ($capability) {
                    'ga4' => 'Google Analytics',
                    'search_console' => 'Search Console',
                    'google_ads' => 'Google Ads',
                    'google_business_profile' => 'Google Business Profile',
                    default => $capability,
                },
                'status' => $status,
                'status_label' => match ($status) {
                    'authorized' => 'Authorized',
                    'scope_required' => 'Scope required',
                    'action_required' => 'Action required',
                    'not_authorized' => 'Not authorized',
                    default => 'Unavailable',
                },
                'missing_scopes' => $missing,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>|null  $capabilities
     * @return list<string>
     */
    public function scopesToRequest(CoreIntegration $integration, ?array $capabilities = null, bool $incremental = true): array
    {
        $target = $this->registry->scopesForCapabilities($capabilities);

        if (! $incremental) {
            return $target;
        }

        $missing = $this->registry->missing($this->grantedScopes($integration), $target);

        // Incremental: request only missing scopes. If none missing but reauth needed for refresh token, request target set.
        return $missing === [] ? $target : $missing;
    }

    private function statusFor(string $auth, bool $hasScope): string
    {
        if (in_array($auth, [GoogleAuthStatus::NOT_CONFIGURED, GoogleAuthStatus::DISABLED], true)) {
            return 'unavailable';
        }

        if (in_array($auth, [GoogleAuthStatus::REVOKED, GoogleAuthStatus::REFRESH_REQUIRED, GoogleAuthStatus::ERROR], true)) {
            return 'action_required';
        }

        if ($auth === GoogleAuthStatus::AUTHORIZATION_REQUIRED) {
            return 'not_authorized';
        }

        return $hasScope ? 'authorized' : 'scope_required';
    }
}
