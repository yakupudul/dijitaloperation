<?php

namespace MoxDop\GoogleAds\Providers;

use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralDatasetExecutor;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProfessionalDatasetExecutor;
use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\GoogleAds\Agents\GoogleAdsAnalyst;
use MoxDop\GoogleAds\Ai\GoogleAdsAiRoutes;
use MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector;
use MoxDop\GoogleAds\Findings\GoogleAdsPerformanceBoundEvidenceEvaluator;

class GoogleAdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.google-ads.loaded', true);

        // Extend the canonical Collection Engine with both bound-asset and
        // provider-resource-first Google Ads datasets. Central aliases are
        // isolated request-family IDs, so resolver ownership stays unambiguous.
        $this->app->singleton(GoogleAdsProfessionalDatasetExecutor::class);
        $this->app->singleton(GoogleAdsCentralDatasetExecutor::class);
        $this->app->tag([
            GoogleAdsProfessionalDatasetExecutor::class,
            GoogleAdsCentralDatasetExecutor::class,
        ], 'collection.dataset_executors');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'google-ads');

        $this->app->make(BoundCollectorRegistry::class)
            ->register($this->app->make(GoogleAdsBoundCollector::class));

        $this->app->make(BoundEvidenceRuleRegistry::class)
            ->register($this->app->make(GoogleAdsPerformanceBoundEvidenceEvaluator::class));

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GOOGLE_ADS_AI_GUIDANCE,
            'name' => GoogleAdsAiRoutes::AI_GUIDANCE_NAME,
            'module' => 'google-ads',
            'description' => 'Grounded Google Ads AI guidance over Findings, Evidence, and Brand Context.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(SkillRegistry::class)->registerRoot(
            'google-ads',
            dirname(__DIR__, 2).'/resources/skills',
        );

        $this->app->make(AgentProfileRegistry::class)->register(GoogleAdsAnalyst::definition());
    }
}
