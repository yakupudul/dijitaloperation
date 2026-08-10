<?php

namespace App\Services\Ai;

use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Integrations\ProviderRegistry;

/**
 * Prepares laravel/ai provider runtime config from agency Integration credentials.
 * Never logs or returns API keys.
 */
class AiProviderRuntimeConfig
{
    public function __construct(
        private readonly OpenAiCredentialResolver $openAi,
        private readonly AnthropicCredentialResolver $anthropic,
        private readonly GeminiCredentialResolver $gemini,
    ) {}

    /**
     * Prepare all AI providers needed for a failover chain.
     *
     * @param  list<string>|null  $providers
     * @return array{prepared: list<string>, missing: list<string>}
     */
    public function prepare(?array $providers = null): array
    {
        $targets = $providers ?? AiProviderCatalog::supported();
        $prepared = [];
        $missing = [];

        foreach ($targets as $provider) {
            $ok = match ($provider) {
                AiProviderCatalog::OPENAI, ProviderRegistry::OPENAI => $this->prepareOpenAi(),
                AiProviderCatalog::ANTHROPIC => $this->prepareAnthropic(),
                AiProviderCatalog::GEMINI => $this->prepareGemini(),
                default => false,
            };

            if ($ok) {
                $prepared[] = $provider;
            } else {
                $missing[] = $provider;
            }
        }

        return compact('prepared', 'missing');
    }

    public function prepareOpenAi(): bool
    {
        $integration = $this->activeIntegration(ProviderRegistry::OPENAI);
        $apiKey = null;

        if ($integration instanceof CoreIntegration) {
            $apiKey = $this->openAi->apiKey($integration);
        } else {
            $apiKey = $this->openAi->envApiKey();
        }

        config([
            'ai.providers.openai.key' => $apiKey,
            'ai.providers.openai.store' => false,
            'ai.providers.openai.url' => (string) config('moxdop.openai.base_url', 'https://api.openai.com/v1'),
        ]);

        return is_string($apiKey) && $apiKey !== '';
    }

    public function prepareAnthropic(): bool
    {
        $integration = $this->activeIntegration(AiProviderCatalog::ANTHROPIC);
        $apiKey = null;

        if ($integration instanceof CoreIntegration) {
            $apiKey = $this->anthropic->apiKey($integration);
        } else {
            $apiKey = $this->anthropic->envApiKey();
        }

        config([
            'ai.providers.anthropic.key' => $apiKey,
            'ai.providers.anthropic.url' => (string) config('moxdop.anthropic.base_url', 'https://api.anthropic.com/v1'),
        ]);

        return is_string($apiKey) && $apiKey !== '';
    }

    public function prepareGemini(): bool
    {
        $integration = $this->activeIntegration(AiProviderCatalog::GEMINI);
        $apiKey = null;

        if ($integration instanceof CoreIntegration) {
            $apiKey = $this->gemini->apiKey($integration);
        } else {
            $apiKey = $this->gemini->envApiKey();
        }

        config([
            'ai.providers.gemini.key' => $apiKey,
        ]);

        return is_string($apiKey) && $apiKey !== '';
    }

    private function activeIntegration(string $provider): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', $provider)
            ->where('status', CoreIntegration::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();
    }
}
