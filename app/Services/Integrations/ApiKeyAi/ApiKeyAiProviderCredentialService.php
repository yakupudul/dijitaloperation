<?php

namespace App\Services\Integrations\ApiKeyAi;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Roles;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Stores / removes the encrypted API key for Groq and OpenRouter integrations (Admin only).
 */
class ApiKeyAiProviderCredentialService
{
    public function __construct(private readonly ApiKeyAiCredentialResolver $resolver) {}

    /** @param array{api_key?: string|null, clear_api_key?: bool} $input */
    public function save(CoreIntegration $integration, array $input, User $user): CoreIntegrationCredential
    {
        $this->assertAllowed($integration, $user);

        if ((bool) ($input['clear_api_key'] ?? false)) {
            $integration->providerCredential()->delete();

            return new CoreIntegrationCredential([
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
                'encrypted_payload' => [],
            ]);
        }

        $input = isset($input['api_key']) && is_string($input['api_key']) ? trim($input['api_key']) : '';
        $apiKey = $input !== '' ? $input : (string) ($this->resolver->providerPayload($integration)['api_key'] ?? '');
        if ($apiKey === '') {
            throw ValidationException::withMessages(['api_key' => 'API key is required (leave blank to keep the stored value).']);
        }

        /** @var CoreIntegrationCredential */
        return CoreIntegrationCredential::query()->updateOrCreate(
            ['integration_id' => $integration->id, 'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER],
            ['encrypted_payload' => ['api_key' => $apiKey], 'expires_at' => null, 'refreshed_at' => null],
        );
    }

    public function remove(CoreIntegration $integration, User $user): void
    {
        $this->assertAllowed($integration, $user);
        $integration->providerCredential()->delete();
        $config = is_array($integration->config) ? $integration->config : [];
        unset($config['connection_status'], $config['last_tested_at'], $config['last_provider_http_status'], $config['models_visible_count']);
        $integration->forceFill(['config' => $config, 'last_error' => null])->save();
    }

    private function assertAllowed(CoreIntegration $integration, User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw new RuntimeException('Only Admin users may configure AI provider credentials.');
        }
        if (! in_array($integration->provider, AiProviderCatalog::apiKeyProviders(), true)) {
            throw new RuntimeException('Integration is not a supported API-key AI provider.');
        }
    }
}
