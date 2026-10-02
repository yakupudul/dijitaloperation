<?php

namespace App\Services\Integrations\ApiKeyAi;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;

/**
 * Credential chain for simple API-key AI providers (Groq, OpenRouter):
 * DB provider credential → config('ai.providers.{provider}.key') (env) → missing.
 * The provider is taken from the Integration row, so one class serves every such provider.
 */
class ApiKeyAiCredentialResolver
{
    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_ENVIRONMENT = 'environment';

    public const string SOURCE_MISSING = 'missing';

    public function apiKey(CoreIntegration $integration): ?string
    {
        $stored = $this->providerPayload($integration)['api_key'] ?? null;
        if (is_string($stored) && filled($stored)) {
            return $stored;
        }

        return $this->envApiKey((string) $integration->provider);
    }

    public function apiKeySource(CoreIntegration $integration): string
    {
        if ($this->hasDatabaseApiKey($integration)) {
            return self::SOURCE_DATABASE;
        }

        return $this->envApiKey((string) $integration->provider) !== null ? self::SOURCE_ENVIRONMENT : self::SOURCE_MISSING;
    }

    public function isConfigured(CoreIntegration $integration): bool
    {
        return $this->apiKey($integration) !== null;
    }

    public function hasDatabaseApiKey(CoreIntegration $integration): bool
    {
        return filled($this->providerPayload($integration)['api_key'] ?? null);
    }

    /** @return array{api_key?: string} */
    public function providerPayload(CoreIntegration $integration): array
    {
        $credential = $integration->relationLoaded('providerCredential')
            ? $integration->providerCredential
            : $integration->providerCredential()->first();
        if (! ($credential instanceof CoreIntegrationCredential)) {
            return [];
        }
        $payload = $credential->encrypted_payload;

        return is_array($payload) ? $payload : [];
    }

    public function envApiKey(string $provider): ?string
    {
        $value = config('ai.providers.'.$provider.'.key');

        return is_string($value) && filled($value) ? $value : null;
    }
}
