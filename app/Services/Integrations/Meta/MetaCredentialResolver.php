<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Support\Integrations\ProviderRegistry;

/**
 * Resolve Meta access token: DB provider credential first, optional env fallback.
 */
class MetaCredentialResolver
{
    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_ENVIRONMENT = 'environment';

    public const string SOURCE_MISSING = 'missing';

    public function isConfigured(CoreIntegration $integration): bool
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

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new \RuntimeException('Integration is not a Meta provider.');
        }
    }
}
