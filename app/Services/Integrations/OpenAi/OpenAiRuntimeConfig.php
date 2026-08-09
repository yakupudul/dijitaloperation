<?php

namespace App\Services\Integrations\OpenAi;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;

/**
 * Prepares laravel/ai OpenAI runtime config from agency Integration credentials.
 * Never logs or returns the API key.
 */
class OpenAiRuntimeConfig
{
    public function __construct(
        private readonly OpenAiCredentialResolver $resolver,
    ) {}

    public function activeIntegration(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', ProviderRegistry::OPENAI)
            ->where('status', CoreIntegration::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();
    }

    /**
     * Apply resolved API key + store=false into runtime config for laravel/ai.
     *
     * @return array{configured: bool, source: string, integration: ?CoreIntegration}
     */
    public function prepare(): array
    {
        $integration = $this->activeIntegration();
        $apiKey = null;
        $source = OpenAiCredentialResolver::SOURCE_MISSING;

        if ($integration instanceof CoreIntegration) {
            $apiKey = $this->resolver->apiKey($integration);
            $source = $this->resolver->apiKeySource($integration);
        } else {
            $envKey = $this->resolver->envApiKey();
            if ($envKey !== null) {
                $apiKey = $envKey;
                $source = OpenAiCredentialResolver::SOURCE_ENVIRONMENT;
            }
        }

        config([
            'ai.providers.openai.key' => $apiKey,
            'ai.providers.openai.store' => false,
            'ai.providers.openai.url' => (string) config('moxdop.openai.base_url', 'https://api.openai.com/v1'),
        ]);

        return [
            'configured' => is_string($apiKey) && $apiKey !== '',
            'source' => $source,
            'integration' => $integration,
        ];
    }

    public function recommendationModel(): string
    {
        $model = config('moxdop.openai.recommendation_model');

        return is_string($model) && filled($model) ? $model : 'gpt-4.1-mini';
    }
}
