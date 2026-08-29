<?php

namespace App\Services\Integrations;

use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
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
 * Operator integrations hub projection.
 *
 * Provider cards are backed by canonical integration/asset state. Website Data is
 * projected directly from Website Digital Assets and production Collection Runs;
 * it does not depend on the demo Site Connector catalog.
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
            $group['group'] = match ((string) ($group['id'] ?? $group['group'] ?? '')) {
                'site_connectors', 'Site Connectors' => __('operator.integrations_ui.groups.connectors'),
                'Platforms & Data' => __('operator.integrations_ui.groups.platforms'),
                'Intelligence Providers' => __('operator.integrations_ui.groups.intelligence'),
                default => (string) ($group['group'] ?? ''),
            };
            $providers = [];
            foreach ($group['providers'] as $provider) {
                $id = (string) ($provider['id'] ?? '');

                $providers[] = match ($id) {
                    ProviderRegistry::GOOGLE => $this->google->hubCard(),
                    ProviderRegistry::META => $this->meta->hubCard(),
                    ProviderRegistry::DATAFORSEO => $this->truthfulProviderCard(
                        $provider,
                        $this->dataForSeoConfigured(),
                        'operator.integrations.dataforseo',
                    ),
                    'wordpress' => $this->wordpressHubCard($provider),
                    ProviderRegistry::OPENAI, AiProviderCatalog::OPENAI => $this->truthfulProviderCard(
                        $provider,
                        $this->openAiConfigured(),
                        'operator.integrations.ai',
                        ['provider' => ProviderRegistry::OPENAI],
                    ),
                    ProviderRegistry::ANTHROPIC, AiProviderCatalog::ANTHROPIC => $this->truthfulProviderCard(
                        $provider,
                        $this->anthropicConfigured(),
                        'operator.integrations.ai',
                        ['provider' => ProviderRegistry::ANTHROPIC],
                    ),
                    ProviderRegistry::GEMINI, AiProviderCatalog::GEMINI => $this->truthfulProviderCard(
                        $provider,
                        $this->geminiConfigured(),
                        'operator.integrations.ai',
                        ['provider' => ProviderRegistry::GEMINI],
                    ),
                    default => $this->truthfulProviderCard($provider, false, 'operator.integrations'),
                };
            }
            $group['providers'] = $providers;
        }
        unset($group);

        array_unshift($groups, [
            'id' => 'website_data',
            'group' => app()->getLocale() === 'tr' ? 'Web Sitesi Verileri' : 'Website Data',
            'providers' => [$this->websiteHubCard()],
        ]);

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteHubCard(): array
    {
        $assetCount = DigitalAsset::query()
            ->where('type', 'website')
            ->count();

        $latestCollection = CollectionRun::query()
            ->whereHas('digitalAsset', fn ($query) => $query->where('type', 'website'))
            ->latest('id')
            ->limit(50)
            ->get()
            ->first(fn (CollectionRun $run): bool => in_array(
                'WEBSITE_DIRECT',
                (array) data_get($run->request_context, 'provider_sources', []),
                true,
            ));

        $ready = $assetCount > 0;

        return [
            'id' => 'website',
            'name' => 'Website',
            'logo_type' => 'website',
            'state' => $ready ? 'connected' : 'not_configured',
            'state_label' => $ready
                ? (app()->getLocale() === 'tr' ? 'Hazır' : 'Ready')
                : (app()->getLocale() === 'tr' ? 'Website gerekli' : 'Website required'),
            'resources_discovered' => null,
            'bound' => null,
            'available' => null,
            'discovery_not_run' => false,
            'last_check' => $latestCollection?->updated_at?->diffForHumans() ?? '—',
            'dependent_assets' => $assetCount,
            'provenance' => 'real',
            'route' => 'operator.integrations.website',
            'route_params' => [],
            'manage_label' => app()->getLocale() === 'tr' ? 'Web Sitelerini Yönet' : 'Manage websites',
            'note' => app()->getLocale() === 'tr'
                ? 'Public crawl, HTTP/HTML teknik analiz, SSL/TLS ve yapılandırıldığında PageSpeed verilerini production Collection Engine ile toplar.'
                : 'Collects public crawl, HTTP/HTML technical analysis, SSL/TLS and, when configured, PageSpeed data through the production Collection Engine.',
        ];
    }

    /**
     * @param  array<string, mixed>  $shell
     * @param  array<string, mixed>  $routeParams
     * @return array<string, mixed>
     */
    private function truthfulProviderCard(array $shell, bool $configured, string $route, array $routeParams = []): array
    {
        $shell['state'] = $configured ? 'configured' : 'not_configured';
        $shell['state_label'] = $configured ? __('operator.states.configured') : __('operator.states.not_configured');
        $shell['resources_discovered'] = null;
        $shell['bound'] = null;
        $shell['available'] = null;
        $shell['discovery_not_run'] = true;
        $shell['last_check'] = '—';
        $shell['dependent_assets'] = 0;
        $shell['provenance'] = 'real';
        $shell['route'] = $route;
        $shell['route_params'] = $routeParams;
        $shell['manage_label'] = __('operator.integrations_ui.configure');
        $shell['note'] = $configured
            ? __('operator.integrations_ui.credentials_configured')
            : __('operator.integrations_ui.not_configured_note');

        return $shell;
    }

    /**
     * @param  array<string, mixed>  $shell
     * @return array<string, mixed>
     */
    private function wordpressHubCard(array $shell): array
    {
        $shell['state'] = 'not_configured';
        $shell['state_label'] = __('operator.states.setup_required');
        $shell['resources_discovered'] = null;
        $shell['bound'] = null;
        $shell['available'] = null;
        $shell['discovery_not_run'] = true;
        $shell['last_check'] = '—';
        $shell['dependent_assets'] = 0;
        $shell['provenance'] = 'real';
        $shell['note'] = __('operator.integrations_ui.wordpress_note');
        $shell['manage_label'] = __('operator.integrations_ui.open_catalog');

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
