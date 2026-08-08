<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Support\Integrations\Google\GoogleOAuthConfig;

/**
 * Resolves Google application credentials with documented precedence:
 * 1) Integration provider credential (encrypted DB)
 * 2) Environment / config fallback (GOOGLE_CLIENT_ID, etc.)
 * 3) Missing
 *
 * Does not read or return OAuth authorization tokens.
 */
class GoogleCredentialResolver
{
    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_ENVIRONMENT = 'environment';

    public const string SOURCE_MISSING = 'missing';

    public function clientId(CoreIntegration $integration): ?string
    {
        return $this->resolveString($integration, 'client_id', GoogleOAuthConfig::envClientId());
    }

    public function clientSecret(CoreIntegration $integration): ?string
    {
        return $this->resolveString($integration, 'client_secret', GoogleOAuthConfig::envClientSecret());
    }

    public function developerToken(CoreIntegration $integration): ?string
    {
        return $this->resolveString($integration, 'developer_token', GoogleOAuthConfig::envDeveloperToken());
    }

    /**
     * @return self::SOURCE_*
     */
    public function clientIdSource(CoreIntegration $integration): string
    {
        return $this->sourceFor($integration, 'client_id', GoogleOAuthConfig::envClientId());
    }

    /**
     * @return self::SOURCE_*
     */
    public function clientSecretSource(CoreIntegration $integration): string
    {
        return $this->sourceFor($integration, 'client_secret', GoogleOAuthConfig::envClientSecret());
    }

    /**
     * @return self::SOURCE_*
     */
    public function developerTokenSource(CoreIntegration $integration): string
    {
        return $this->sourceFor($integration, 'developer_token', GoogleOAuthConfig::envDeveloperToken());
    }

    public function isAppConfigured(CoreIntegration $integration): bool
    {
        return $this->clientId($integration) !== null && $this->clientSecret($integration) !== null;
    }

    public function hasDeveloperToken(CoreIntegration $integration): bool
    {
        return $this->developerToken($integration) !== null;
    }

    /**
     * @return list<string>
     */
    public function missingAppKeys(CoreIntegration $integration): array
    {
        $missing = [];

        if ($this->clientId($integration) === null) {
            $missing[] = 'OAuth Client ID';
        }

        if ($this->clientSecret($integration) === null) {
            $missing[] = 'OAuth Client Secret';
        }

        return $missing;
    }

    /**
     * Non-secret Client ID from DB only (safe to display when present).
     */
    public function databaseClientId(CoreIntegration $integration): ?string
    {
        $payload = $this->providerPayload($integration);
        $value = $payload['client_id'] ?? null;

        return is_string($value) && filled($value) ? $value : null;
    }

    public function hasDatabaseClientSecret(CoreIntegration $integration): bool
    {
        $payload = $this->providerPayload($integration);

        return filled($payload['client_secret'] ?? null);
    }

    public function hasDatabaseDeveloperToken(CoreIntegration $integration): bool
    {
        $payload = $this->providerPayload($integration);

        return filled($payload['developer_token'] ?? null);
    }

    /**
     * @return array{client_id?: string, client_secret?: string, developer_token?: string}
     */
    public function providerPayload(CoreIntegration $integration): array
    {
        $credential = $integration->relationLoaded('providerCredential')
            ? $integration->providerCredential
            : $integration->providerCredential()->first();

        if (! $credential instanceof CoreIntegrationCredential) {
            return [];
        }

        $payload = $credential->encrypted_payload;

        return is_array($payload) ? $payload : [];
    }

    public function configurationLabel(string $source, bool $configured): string
    {
        if ($source === self::SOURCE_DATABASE) {
            return 'Configured';
        }

        if ($source === self::SOURCE_ENVIRONMENT) {
            return 'Configured by environment';
        }

        return $configured ? 'Configured' : 'Missing';
    }

    private function resolveString(CoreIntegration $integration, string $key, ?string $envFallback): ?string
    {
        $payload = $this->providerPayload($integration);
        $dbValue = $payload[$key] ?? null;

        if (is_string($dbValue) && filled($dbValue)) {
            return $dbValue;
        }

        return filled($envFallback) ? $envFallback : null;
    }

    /**
     * @return self::SOURCE_*
     */
    private function sourceFor(CoreIntegration $integration, string $key, ?string $envFallback): string
    {
        $payload = $this->providerPayload($integration);
        $dbValue = $payload[$key] ?? null;

        if (is_string($dbValue) && filled($dbValue)) {
            return self::SOURCE_DATABASE;
        }

        if (filled($envFallback)) {
            return self::SOURCE_ENVIRONMENT;
        }

        return self::SOURCE_MISSING;
    }
}
