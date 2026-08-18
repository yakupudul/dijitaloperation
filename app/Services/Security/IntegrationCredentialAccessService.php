<?php

namespace App\Services\Security;

use App\Enums\Security\SecretClass;
use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\Google\GoogleCredentialBroker;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Meta\MetaCredentialBroker;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Security\EphemeralSecret;
use InvalidArgumentException;

/**
 * Canonical server-side credential access boundary (Prompt 64).
 * Purpose-specific reads only — never dumpAllCredentials().
 * Agents / browser / controllers must not call this for display.
 */
final class IntegrationCredentialAccessService
{
    public function __construct(
        private readonly GoogleCredentialBroker $googleBroker,
        private readonly GoogleCredentialResolver $googleResolver,
        private readonly MetaCredentialBroker $metaBroker,
        private readonly MetaCredentialResolver $metaResolver,
        private readonly OpenAiCredentialResolver $openAi,
        private readonly AnthropicCredentialResolver $anthropic,
        private readonly GeminiCredentialResolver $gemini,
        private readonly DataForSeoCredentialResolver $dataForSeo,
    ) {}

    public function secretClassFor(string $field): SecretClass
    {
        return match ($field) {
            'access_token', 'refresh_token', 'api_key', 'client_secret', 'developer_token', 'password', 'login' => SecretClass::RecoverableCredential,
            'expires_at', 'scopes', 'status', 'provider_account_id' => SecretClass::NonSecretSecurityMetadata,
            default => SecretClass::RecoverableCredential,
        };
    }

    public function googleAccessToken(CoreIntegration $integration, ?string $capability = null): EphemeralSecret
    {
        $token = $this->googleBroker->accessTokenFor($integration, $capability);

        return new EphemeralSecret(
            value: $token,
            purpose: 'google_provider_request',
            provider: ProviderRegistry::GOOGLE,
            integrationId: (int) $integration->id,
        );
    }

    public function googleClientSecret(CoreIntegration $integration): ?EphemeralSecret
    {
        $secret = $this->googleResolver->clientSecret($integration);
        if ($secret === null || $secret === '') {
            return null;
        }

        return new EphemeralSecret(
            value: $secret,
            purpose: 'google_oauth_client_secret',
            provider: ProviderRegistry::GOOGLE,
            integrationId: (int) $integration->id,
        );
    }

    public function metaAccessToken(CoreIntegration $integration): EphemeralSecret
    {
        return $this->metaBroker->accessTokenFor($integration);
    }

    public function openAiApiKey(CoreIntegration $integration): ?EphemeralSecret
    {
        $key = $this->openAi->apiKey($integration);
        if ($key === null || $key === '') {
            return null;
        }

        return new EphemeralSecret(
            value: $key,
            purpose: 'openai_provider_request',
            provider: 'openai',
            integrationId: (int) $integration->id,
        );
    }

    public function anthropicApiKey(CoreIntegration $integration): ?EphemeralSecret
    {
        $key = $this->anthropic->apiKey($integration);
        if ($key === null || $key === '') {
            return null;
        }

        return new EphemeralSecret(
            value: $key,
            purpose: 'anthropic_provider_request',
            provider: 'anthropic',
            integrationId: (int) $integration->id,
        );
    }

    public function geminiApiKey(CoreIntegration $integration): ?EphemeralSecret
    {
        $key = $this->gemini->apiKey($integration);
        if ($key === null || $key === '') {
            return null;
        }

        return new EphemeralSecret(
            value: $key,
            purpose: 'gemini_provider_request',
            provider: 'gemini',
            integrationId: (int) $integration->id,
        );
    }

    /**
     * @return array{login: ?EphemeralSecret, password: ?EphemeralSecret}
     */
    public function dataForSeoBasicAuth(CoreIntegration $integration): array
    {
        $login = $this->dataForSeo->login($integration);
        $password = $this->dataForSeo->password($integration);

        return [
            'login' => ($login !== null && $login !== '')
                ? new EphemeralSecret($login, 'dataforseo_login', 'dataforseo', (int) $integration->id)
                : null,
            'password' => ($password !== null && $password !== '')
                ? new EphemeralSecret($password, 'dataforseo_password', 'dataforseo', (int) $integration->id)
                : null,
        ];
    }

    /**
     * Presence-only status for UI — never returns secret material.
     *
     * @return array{provider: string, configured: bool, source: string}
     */
    public function statusFor(CoreIntegration $integration): array
    {
        return match ($integration->provider) {
            ProviderRegistry::GOOGLE => [
                'provider' => ProviderRegistry::GOOGLE,
                'configured' => $this->googleResolver->isAppConfigured($integration),
                'source' => $this->googleResolver->clientSecretSource($integration),
            ],
            ProviderRegistry::META => [
                'provider' => ProviderRegistry::META,
                'configured' => $this->metaResolver->hasTenantAuthorization($integration),
                'source' => $this->metaResolver->accessTokenSource($integration),
            ],
            default => [
                'provider' => (string) $integration->provider,
                'configured' => false,
                'source' => 'unknown',
            ],
        };
    }

    /**
     * Hard deny — Agents and Assistants must not call credential access.
     */
    public function denyAgentAccess(string $caller): never
    {
        throw new InvalidArgumentException('AI_CREDENTIAL_ACCESS_FORBIDDEN:'.$caller);
    }
}
