<?php

namespace App\Services\Integrations\Google;

use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Exceptions\Integrations\GoogleAuthorizationException;
use App\Models\CoreIntegration;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\ProviderRegistry;

/**
 * Sole application boundary for obtaining a valid Google access token.
 * Never serializes tokens into queues, UI, or logs.
 */
final class GoogleCredentialBroker
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleScopeCoverageService $coverage,
        private readonly GoogleCredentialResolver $credentials,
        private readonly GoogleScopeRegistry $scopes,
    ) {}

    /**
     * @throws GoogleAuthenticationException
     * @throws GoogleAuthorizationException
     */
    public function accessTokenFor(CoreIntegration $integration, ?string $capability = null): string
    {
        if ($integration->provider !== ProviderRegistry::GOOGLE) {
            throw new GoogleAuthenticationException('Integration is not Google.');
        }

        $status = GoogleAuthStatus::for($integration);
        if (in_array($status, [
            GoogleAuthStatus::NOT_CONFIGURED,
            GoogleAuthStatus::AUTHORIZATION_REQUIRED,
            GoogleAuthStatus::REVOKED,
            GoogleAuthStatus::DISABLED,
            GoogleAuthStatus::REFRESH_REQUIRED,
        ], true)) {
            throw new GoogleAuthenticationException('Google Integration authorization is not usable ('.$status.').');
        }

        if ($capability !== null) {
            $missing = $this->coverage->missingScopes($integration, [$capability]);
            if ($missing !== []) {
                throw new GoogleAuthorizationException(
                    'Google Connector scope required for '.$capability.'.',
                    $missing,
                    $capability,
                );
            }
        }

        return $this->oauth->accessTokenOrFail($integration);
    }

    /**
     * @return array{configured: bool, developer_token_configured: bool, oauth_scope_ready: bool}
     */
    public function adsApplicationReadiness(CoreIntegration $integration): array
    {
        return [
            'configured' => $this->credentials->isAppConfigured($integration),
            'developer_token_configured' => $this->credentials->hasDeveloperToken($integration),
            'oauth_scope_ready' => $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_GOOGLE_ADS),
        ];
    }

    /**
     * Developer token from application config/env only — never from OAuth payload.
     */
    public function adsDeveloperToken(CoreIntegration $integration): ?string
    {
        return $this->credentials->developerToken($integration);
    }

    /**
     * @return list<string>
     */
    public function requiredScopes(string $capability): array
    {
        return $this->scopes->scopesForCapabilities([$capability]);
    }
}
