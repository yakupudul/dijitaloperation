<?php

namespace App\Services\Integrations\OpenAi;

use App\Models\CoreIntegration;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Integrations\ProviderRegistry;

/**
 * Backward-compatible OpenAI runtime helper.
 * Prefer AiProviderRuntimeConfig for multi-provider preparation.
 */
class OpenAiRuntimeConfig
{
    public function __construct(
        private readonly OpenAiCredentialResolver $resolver,
        private readonly AiProviderRuntimeConfig $aiRuntime,
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
     * @return array{configured: bool, source: string, integration: ?CoreIntegration}
     */
    public function prepare(): array
    {
        $integration = $this->activeIntegration();
        $configured = $this->aiRuntime->prepareOpenAi();
        $source = OpenAiCredentialResolver::SOURCE_MISSING;

        if ($integration instanceof CoreIntegration) {
            $source = $this->resolver->apiKeySource($integration);
        } elseif ($this->resolver->envApiKey() !== null) {
            $source = OpenAiCredentialResolver::SOURCE_ENVIRONMENT;
        }

        return [
            'configured' => $configured,
            'source' => $source,
            'integration' => $integration,
        ];
    }

    /**
     * @deprecated Prefer route-owned model via AiRouteResolver.
     */
    public function recommendationModel(): string
    {
        return AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI);
    }
}
