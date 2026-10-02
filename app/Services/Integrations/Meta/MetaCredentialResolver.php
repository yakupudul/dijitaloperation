<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;

/**
 * Resolve Meta credentials with canonical ownership:
 * - Application App ID/Secret: encrypted provider credential first, environment fallback
 * - Tenant authorization token: encrypted provider credential first, optional env fallback
 *
 * Recoverable secrets never belong in Integration config JSON or Livewire snapshots.
 */
class MetaCredentialResolver
{
    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_ENVIRONMENT = 'environment';

    public const string SOURCE_MISSING = 'missing';

    /**
     * Legacy alias: tenant authorization token present (not Meta App configuration).
     */
    public function isConfigured(CoreIntegration $integration): bool
    {
        return $this->hasTenantAuthorization($integration);
    }

    public function isApplicationConfigured(?CoreIntegration $integration = null): bool
    {
        return $this->appId($integration) !== null && $this->appSecret($integration) !== null;
    }

    public function hasTenantAuthorization(CoreIntegration $integration): bool
    {
        return $this->accessToken($integration) !== null;
    }

    public function appId(?CoreIntegration $integration = null): ?string
    {
        if ($integration instanceof CoreIntegration) {
            $this->assertMeta($integration);
            $payload = $this->providerPayload($integration);
            $value = isset($payload['app_id']) && is_string($payload['app_id'])
                ? trim($payload['app_id'])
                : '';
            if ($value !== '') {
                return $value;
            }
        }

        return MetaApiConfig::appId();
    }

    public function appSecret(?CoreIntegration $integration = null): ?string
    {
        if ($integration instanceof CoreIntegration) {
            $this->assertMeta($integration);
            $payload = $this->providerPayload($integration);
            $value = isset($payload['app_secret']) && is_string($payload['app_secret'])
                ? trim($payload['app_secret'])
                : '';
            if ($value !== '') {
                return $value;
            }
        }

        return MetaApiConfig::appSecret();
    }

    /**
     * @return self::SOURCE_*
     */
    public function appIdSource(?CoreIntegration $integration = null): string
    {
        if ($integration instanceof CoreIntegration && $this->databaseAppId($integration) !== null) {
            return self::SOURCE_DATABASE;
        }

        return MetaApiConfig::appId() !== null ? self::SOURCE_ENVIRONMENT : self::SOURCE_MISSING;
    }

    /**
     * @return self::SOURCE_*
     */
    public function appSecretSource(?CoreIntegration $integration = null): string
    {
        if ($integration instanceof CoreIntegration && $this->hasDatabaseAppSecret($integration)) {
            return self::SOURCE_DATABASE;
        }

        return MetaApiConfig::appSecret() !== null ? self::SOURCE_ENVIRONMENT : self::SOURCE_MISSING;
    }

    /**
     * Non-secret App ID from DB only (safe to display when present).
     */
    public function databaseAppId(CoreIntegration $integration): ?string
    {
        $payload = $this->providerPayload($integration);
        $value = $payload['app_id'] ?? null;

        return is_string($value) && filled($value) ? $value : null;
    }

    public function hasDatabaseAppSecret(CoreIntegration $integration): bool
    {
        $payload = $this->providerPayload($integration);

        return filled($payload['app_secret'] ?? null);
    }

    public function appAccessToken(?CoreIntegration $integration = null): ?string
    {
        $appId = $this->appId($integration);
        $secret = $this->appSecret($integration);
        if ($appId === null || $secret === null) {
            return null;
        }

        return $appId.'|'.$secret;
    }

    public function appSecretProof(CoreIntegration $integration, string $accessToken): ?string
    {
        $secret = $this->appSecret($integration);
        if ($secret === null || $accessToken === '') {
            return null;
        }

        return hash_hmac('sha256', $accessToken, $secret);
    }

    public function accessToken(CoreIntegration $integration): ?string
    {
        $this->assertMeta($integration);

        $payload = $this->providerPayload($integration);
        $token = isset($payload['access_token']) && is_string($payload['access_token'])
            ? trim($payload['access_token'])
            : '';
        if ($token !== '') {
            return $token;
        }

        $env = trim((string) config('moxdop.meta.access_token', ''));

        return $env !== '' ? $env : null;
    }

    public function accessTokenSource(CoreIntegration $integration): string
    {
        $payload = $this->providerPayload($integration);
        if (isset($payload['access_token']) && is_string($payload['access_token']) && trim($payload['access_token']) !== '') {
            return self::SOURCE_DATABASE;
        }

        if (trim((string) config('moxdop.meta.access_token', '')) !== '') {
            return self::SOURCE_ENVIRONMENT;
        }

        return self::SOURCE_MISSING;
    }

    public function hasDatabaseAccessToken(CoreIntegration $integration): bool
    {
        return $this->accessTokenSource($integration) === self::SOURCE_DATABASE;
    }

    /**
     * @return array<string, mixed>
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

    /**
     * Assert Integration config JSON never stores recoverable Meta secrets.
     *
     * @param  array<string, mixed>  $config
     */
    public function assertNoSecretsInPublicConfig(array $config): void
    {
        foreach (['app_secret', 'access_token', 'client_secret', 'meta_app_secret'] as $key) {
            if (array_key_exists($key, $config) && filled($config[$key] ?? null)) {
                throw new \InvalidArgumentException('Meta secrets must not be stored in integration config.');
            }
        }
    }

    public function configurationLabel(string $source, bool $present): string
    {
        if (! $present) {
            return 'Not configured';
        }

        return match ($source) {
            self::SOURCE_DATABASE => 'Stored securely in MoxDOP',
            self::SOURCE_ENVIRONMENT => 'Configured by environment',
            default => 'Configured',
        };
    }

    public function applicationConfigurationLabel(?CoreIntegration $integration = null): string
    {
        return $this->isApplicationConfigured($integration)
            ? 'Configured'
            : 'Not configured';
    }

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new \RuntimeException('Integration is not a Meta provider.');
        }
    }
}
