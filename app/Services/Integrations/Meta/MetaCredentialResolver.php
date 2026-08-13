<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;

/**
 * Resolve Meta credentials with canonical ownership:
 * - Application App ID/Secret: deployment config only (never tenant credential rows)
 * - Tenant authorization token: DB provider credential first, optional env fallback
 *
 * Prompt 22 will productionize OAuth lifecycle; Prompt 21 defines ownership.
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

    public function isApplicationConfigured(): bool
    {
        return MetaApiConfig::isApplicationConfigured();
    }

    public function hasTenantAuthorization(CoreIntegration $integration): bool
    {
        return $this->accessToken($integration) !== null;
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
     * Assert tenant credential payload never stores Meta App Secret.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertNoAppSecretInTenantPayload(array $payload): void
    {
        foreach (['app_secret', 'client_secret', 'meta_app_secret'] as $key) {
            if (array_key_exists($key, $payload) && filled($payload[$key] ?? null)) {
                throw new \InvalidArgumentException('Meta App Secret must not be stored in tenant credentials.');
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

    public function applicationConfigurationLabel(): string
    {
        return $this->isApplicationConfigured()
            ? 'Complete'
            : 'Incomplete — Meta App ID/Secret (Prompt 22 OAuth)';
    }

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new \RuntimeException('Integration is not a Meta provider.');
        }
    }
}
