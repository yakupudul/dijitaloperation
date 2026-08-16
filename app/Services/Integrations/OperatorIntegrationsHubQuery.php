<?php

namespace App\Services\Integrations;

use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Services\Integrations\Meta\MetaIntegrationReadModel;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Demo\GlobalOperatingFixtures;
use App\Support\Integrations\ProviderRegistry;

/**
 * Frozen `/app/integrations` hub projection.
 *
 * Google and Meta cards are backed by canonical CoreIntegration state.
 * Other provider cards report truthful configuration / not-connected state —
 * never fabricated connected / last_check Demo values (Prompt 67).
 */
final class OperatorIntegrationsHubQuery
{
    public function __construct(
        private readonly GoogleIntegrationReadModel $google = new GoogleIntegrationReadModel,
        private readonly MetaIntegrationReadModel $meta = new MetaIntegrationReadModel,
        private readonly DataForSeoCredentialResolver $dataForSeo = new DataForSeoCredentialResolver,
        private readonly OpenAiCredentialResolver $openAi = new OpenAiCredentialResolver,
        private readonly AnthropicCredentialResolver $anthropic = new AnthropicCredentialResolver,
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
                    ProviderRegistry::DATAFORSEO => $this->truthfulProviderCard($provider, $this->dataForSeoConfigured()),
                    'wordpress' => $this->wordpressHubCard($provider),
                    ProviderRegistry::OPENAI, AiProviderCatalog::OPENAI => $this->truthfulProviderCard($provider, $this->openAiConfigured()),
                    ProviderRegistry::ANTHROPIC, AiProviderCatalog::ANTHROPIC => $this->truthfulProviderCard($provider, $this->anthropicConfigured()),
                    default => $this->truthfulProviderCard($provider, false),
                };
            }
            $group['providers'] = $providers;
        }
        unset($group);

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $shell
     * @return array<string, mixed>
     */
    private function truthfulProviderCard(array $shell, bool $configured): array
    {
        $shell['state'] = $configured ? 'configured' : 'not_connected';
        $shell['state_label'] = $configured ? 'Configured' : 'Not connected';
        $shell['resources_discovered'] = $shell['resources_discovered'] === null ? null : 0;
        $shell['bound'] = $shell['bound'] === null ? null : 0;
        $shell['available'] = $shell['available'] === null ? null : 0;
        $shell['last_check'] = '—';
        $shell['dependent_assets'] = 0;
        $shell['provenance'] = 'real';
        $shell['note'] = $configured
            ? ($shell['note'] ?? 'Provider credentials are configured. Connection health is verified on the provider detail surface.')
            : 'Not connected — configure credentials before expecting live provider data. No sample connection state is shown.';

        return $shell;
    }

    /**
     * @param  array<string, mixed>  $shell
     * @return array<string, mixed>
     */
    private function wordpressHubCard(array $shell): array
    {
        $shell['state'] = 'not_connected';
        $shell['state_label'] = 'Setup required';
        $shell['resources_discovered'] = 0;
        $shell['bound'] = 0;
        $shell['available'] = 0;
        $shell['last_check'] = '—';
        $shell['dependent_assets'] = 0;
        $shell['provenance'] = 'real';
        $shell['note'] = 'WordPress site connector catalog is available for install packages. No fabricated Connected state — bind a site connector to see real connection health.';
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
}
