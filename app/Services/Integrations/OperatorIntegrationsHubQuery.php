<?php

namespace App\Services\Integrations;

use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Services\Integrations\Meta\MetaIntegrationReadModel;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Demo\GlobalOperatingFixtures;
use App\Support\Integrations\ProviderRegistry;

/**
 * Frozen `/app/integrations` hub projection.
 *
 * Provider cards are backed by canonical CoreIntegration credential state.
 * Hub CTAs always lead to a real configuration or workspace surface.
 */
final class OperatorIntegrationsHubQuery
{
    public function __construct(
        private readonly GoogleIntegrationReadModel $google = new GoogleIntegrationReadModel,
        private readonly MetaIntegrationReadModel $meta = new MetaIntegrationReadModel,
        private readonly DataForSeoCredentialResolver $dataForSeo = new DataForSeoCredentialResolver,
        private readonly OpenAiCredentialResolver $openAi = new OpenAiCredentialResolver,
        private readonly AnthropicCredentialResolver $anthropic = new AnthropicCredentialResolver,
        private readonly GeminiCredentialResolver $gemini = new GeminiCredentialResolver,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function groups(): array
    {
        $groups = GlobalOperatingFixtures::integrationsHub();

        foreach ($groups as &$group) {
            $providers = [];
            foreach ($group['providers'] as $provider) {
                $id = (string) ($provider['id'] ?? '');

                $providers[] = match ($id) {
                    ProviderRegistry::GOOGLE => $this->google->hubCard(),
                    ProviderRegistry::META => $this->meta->hubCard(),
                    ProviderRegistry::DATAFORSEO => $this->truthfulProviderCard(
                        $provider,
                        $this->dataForSeoConfigured(),
                        'demo.integrations.dataforseo',
                    ),
                    'wordpress' => $this->wordpressHubCard($provider),
                    ProviderRegistry::OPENAI, AiProviderCatalog::OPENAI => $this->truthfulProviderCard(
                        $provider,
                        $this->openAiConfigured(),
                        'demo.integrations.ai',
                        ['provider' => ProviderRegistry::OPENAI],
                    ),
                    ProviderRegistry::ANTHROPIC, AiProviderCatalog::ANTHROPIC => $this->truthfulProviderCard(
                        $provider,
                        $this->anthropicConfigured(),
                        'demo.integrations.ai',
                        ['provider' => ProviderRegistry::ANTHROPIC],
                    ),
                    ProviderRegistry::GEMINI, AiProviderCatalog::GEMINI => $this->truthfulProviderCard(
                        $provider,
                        $this->geminiConfigured(),
                        'demo.integrations.ai',
                        ['provider' => ProviderRegistry::GEMINI],
                    ),
                    default => $this->truthfulProviderCard($provider, false, 'demo.integrations'),
                };
            }
            $group['providers'] = $providers;
        }
        unset($group);

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $shell
     * @param  array<string, mixed>  $routeParams
     * @return array<string, mixed>
     */
    private function truthfulProviderCard(array $shell, bool $configured, string $route, array $routeParams = []): array
    {
        $shell['state'] = $configured ? 'configured' : 'not_configured';
        $shell['state_label'] = $configured ? 'Configured' : 'Not configured';
        $shell['resources_discovered'] = null;
        $shell['bound'] = null;
        $shell['available'] = null;
        $shell['discovery_not_run'] = true;
        $shell['last_check'] = '—';
        $shell['dependent_assets'] = 0;
        $shell['provenance'] = 'real';
        $shell['route'] = $route;
        $shell['route_params'] = $routeParams;
        $shell['manage_label'] = 'Configure';
        $shell['note'] = $configured
            ? 'Provider credentials are configured. Stored credentials are not a live connection.'
            : 'Not configured — save credentials before expecting live provider data.';

        return $shell;
    }

    /**
     * @param  array<string, mixed>  $shell
     * @return array<string, mixed>
     */
    private function wordpressHubCard(array $shell): array
    {
        $shell['state'] = 'not_configured';
        $shell['state_label'] = 'Setup required';
        $shell['resources_discovered'] = null;
        $shell['bound'] = null;
        $shell['available'] = null;
        $shell['discovery_not_run'] = true;
        $shell['last_check'] = '—';
        $shell['dependent_assets'] = 0;
        $shell['provenance'] = 'real';
        $shell['note'] = 'WordPress site connector catalog is available for install packages. Bind a site connector to see real connection health.';
        $shell['manage_label'] = 'Open catalog';

        return $shell;
    }

    private function dataForSeoConfigured(): bool
    {
        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::DATAFORSEO)->first();

        return $integration !== null && $this->dataForSeo->isConfigured($integration);
    }

    private function openAiConfigured(): bool
    {
        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::OPENAI)->first();

        return $integration !== null && $this->openAi->isConfigured($integration);
    }

    private function anthropicConfigured(): bool
    {
        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::ANTHROPIC)->first();

        return $integration !== null && $this->anthropic->isConfigured($integration);
    }

    private function geminiConfigured(): bool
    {
        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::GEMINI)->first();

        return $integration !== null && $this->gemini->isConfigured($integration);
    }
}
