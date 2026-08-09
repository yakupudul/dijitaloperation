<?php

namespace App\Services\Integrations\DataForSeo;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;

/**
 * Resolves DataForSEO API credentials with documented precedence:
 * 1) Integration provider credential (encrypted DB)
 * 2) Environment / config fallback (DATAFORSEO_API_LOGIN / DATAFORSEO_API_PASSWORD)
 * 3) Missing
 */
class DataForSeoCredentialResolver
{
    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_ENVIRONMENT = 'environment';

    public const string SOURCE_MISSING = 'missing';

    public function login(CoreIntegration $integration): ?string
    {
        return $this->resolveString($integration, 'login', $this->envLogin());
    }

    public function password(CoreIntegration $integration): ?string
    {
        return $this->resolveString($integration, 'password', $this->envPassword());
    }

    /**
     * @return self::SOURCE_*
     */
    public function loginSource(CoreIntegration $integration): string
    {
        return $this->sourceFor($integration, 'login', $this->envLogin());
    }

    /**
     * @return self::SOURCE_*
     */
    public function passwordSource(CoreIntegration $integration): string
    {
        return $this->sourceFor($integration, 'password', $this->envPassword());
    }

    public function isConfigured(CoreIntegration $integration): bool
    {
        return $this->login($integration) !== null && $this->password($integration) !== null;
    }

    /**
     * Non-secret API login from DB only (safe to display when present).
     */
    public function databaseLogin(CoreIntegration $integration): ?string
    {
        $payload = $this->providerPayload($integration);
        $value = $payload['login'] ?? null;

        return is_string($value) && filled($value) ? $value : null;
    }

    public function hasDatabasePassword(CoreIntegration $integration): bool
    {
        $payload = $this->providerPayload($integration);

        return filled($payload['password'] ?? null);
    }

    /**
     * @return array{login?: string, password?: string}
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

    public function envLogin(): ?string
    {
        $value = config('moxdop.dataforseo.login');

        return is_string($value) && filled($value) ? $value : null;
    }

    public function envPassword(): ?string
    {
        $value = config('moxdop.dataforseo.password');

        return is_string($value) && filled($value) ? $value : null;
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
