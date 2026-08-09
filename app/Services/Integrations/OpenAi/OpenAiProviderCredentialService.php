<?php

namespace App\Services\Integrations\OpenAi;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin-managed OpenAI provider credentials (API key).
 */
class OpenAiProviderCredentialService
{
    public function __construct(
        private readonly OpenAiCredentialResolver $resolver,
    ) {}

    public function assertAdmin(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw new RuntimeException('Only Admin users may configure OpenAI provider credentials.');
        }
    }

    /**
     * @param  array{
     *     api_key?: string|null,
     *     clear_api_key?: bool
     * }  $input
     */
    public function save(CoreIntegration $integration, array $input, User $user): CoreIntegrationCredential
    {
        $this->assertAdmin($user);
        $this->assertOpenAi($integration);

        $existing = $this->resolver->providerPayload($integration);
        $clear = (bool) ($input['clear_api_key'] ?? false);
        $apiKeyInput = isset($input['api_key']) && is_string($input['api_key'])
            ? trim($input['api_key'])
            : '';

        if ($clear) {
            $existingCredential = $integration->providerCredential()->first();
            if ($existingCredential instanceof CoreIntegrationCredential) {
                $existingCredential->delete();
            }

            // Return a detached placeholder for callers that expect a model instance.
            return new CoreIntegrationCredential([
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
                'encrypted_payload' => [],
            ]);
        }

        if ($apiKeyInput !== '') {
            $apiKey = $apiKeyInput;
        } else {
            $apiKey = (string) ($existing['api_key'] ?? '');
        }

        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'api_key' => 'API key is required (leave blank to keep the stored value).',
            ]);
        }

        /** @var CoreIntegrationCredential $credential */
        $credential = CoreIntegrationCredential::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            ],
            [
                'encrypted_payload' => [
                    'api_key' => $apiKey,
                ],
                'expires_at' => null,
                'refreshed_at' => null,
            ],
        );

        return $credential;
    }

    public function remove(CoreIntegration $integration, User $user): void
    {
        $this->assertAdmin($user);
        $this->assertOpenAi($integration);

        $integration->providerCredential()->delete();

        $config = is_array($integration->config) ? $integration->config : [];
        unset(
            $config['connection_status'],
            $config['last_tested_at'],
            $config['last_provider_http_status'],
            $config['models_visible_count'],
        );

        $integration->forceFill([
            'config' => $config,
            'last_error' => null,
        ])->save();
    }

    private function assertOpenAi(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::OPENAI) {
            throw new RuntimeException('Integration is not an OpenAI provider.');
        }
    }
}
