<?php

namespace MoxDop\Website\Providers;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\Website\Agents\Ga4MeasurementAnalyst;
use MoxDop\Website\Agents\GscOrganicSearchAnalyst;
use MoxDop\Website\Agents\WebsiteBrandDiscoveryAnalyst;
use MoxDop\Website\Agents\WebsiteSeoAnalyst;
use MoxDop\Website\Ai\WebsiteAiRoutes;
use MoxDop\Website\Collection\Ga4BoundCollector;
use MoxDop\Website\Collection\SearchConsoleBoundCollector;
use MoxDop\Website\Findings\WebsitePerformanceBoundEvidenceEvaluator;
use MoxDop\Website\Workspace\OperatorWebsiteWorkspace;

class WebsiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.website.loaded', true);
        $this->app->bind(WebsiteOperatorWorkspace::class, OperatorWebsiteWorkspace::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'website');

        $registry = $this->app->make(BoundCollectorRegistry::class);
        $registry->register($this->app->make(SearchConsoleBoundCollector::class));
        $registry->register($this->app->make(Ga4BoundCollector::class));

        $this->app->make(BoundEvidenceRuleRegistry::class)
            ->register($this->app->make(WebsitePerformanceBoundEvidenceEvaluator::class));

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::WEBSITE_AI_GUIDANCE,
            'name' => WebsiteAiRoutes::AI_GUIDANCE_NAME,
            'module' => 'website',
            'description' => 'Grounded Website AI guidance over Findings, Evidence, and Brand Context.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT,
            'name' => WebsiteAiRoutes::DISCOVERY_CONTEXT_NAME,
            'module' => 'website',
            'description' => 'Bounded Website public Discovery Brand inference proposals for human review.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GA4_AI_GUIDANCE,
            'name' => WebsiteAiRoutes::GA4_AI_GUIDANCE_NAME,
            'module' => 'website',
            'description' => 'Grounded GA4 measurement guidance. Designed — live specialist execution not claimed; collectors may still be Website-scoped.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GSC_AI_GUIDANCE,
            'name' => WebsiteAiRoutes::GSC_AI_GUIDANCE_NAME,
            'module' => 'website',
            'description' => 'Grounded Search Console organic-demand guidance. Designed — live specialist execution not claimed; collectors may still be Website-scoped.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(SkillRegistry::class)->registerRoot(
            'website',
            dirname(__DIR__, 2).'/resources/skills',
        );

        $this->app->make(AgentProfileRegistry::class)->register(WebsiteSeoAnalyst::definition());
        $this->app->make(AgentProfileRegistry::class)->register(WebsiteBrandDiscoveryAnalyst::definition());
        $this->app->make(AgentProfileRegistry::class)->register(Ga4MeasurementAnalyst::definition());
        $this->app->make(AgentProfileRegistry::class)->register(GscOrganicSearchAnalyst::definition());
    }
}
