<?php

namespace App\Services\Integrations\Gemini;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;

/**
 * Resolves Gemini API credentials: DB provider credential → GEMINI_API_KEY → missing.
 * Separate from Google OAuth Integration credentials.
 */
class GeminiCredentialResolver
{
    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_ENVIRONMENT = 'environment';

    public const string SOURCE_MISSING = 'missing';

    public function apiKey(CoreIntegration $integration): ?string
    {
        return $this->resolveString($integration, 'api_key', $this->envApiKey());
    }

    /**
     * @return self::SOURCE_*
     */
    public function apiKeySource(CoreIntegration $integration): string
    {
        return $this->sourceFor($integration, 'api_key', $this->envApiKey());
    }

    public function isConfigured(CoreIntegration $integration): bool
    {
        return $this->apiKey($integration) !== null;
    }

    public function hasDatabaseApiKey(CoreIntegration $integration): bool
    {
        $payload = $this->providerPayload($integration);

        return filled($payload['api_key'] ?? null);
    }

    /**
     * @return array{api_key?: string}
     */
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

    public function envApiKey(): ?string
    {
        $value = config('moxdop.gemini.api_key');

        if (is_string($value) && filled($value)) {
            return $value;
        }

        $legacy = config('ai.providers.gemini.key');

        return is_string($legacy) && filled($legacy) ? $legacy : null;
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
