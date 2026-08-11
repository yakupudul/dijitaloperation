<?php

namespace MoxDop\MetaAds\Providers;

use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\MetaAds\Agents\MetaAdsAnalyst;
use MoxDop\MetaAds\Ai\MetaAdsAiRoutes;

/**
 * Meta Ads module — Digital Asset domain, AI guidance, and Skills (V1).
 */
class MetaAdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound('moxdop.meta_ads.loaded')) {
            $this->app->instance('moxdop.meta_ads.loaded', true);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'meta-ads');

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::META_ADS_AI_GUIDANCE,
            'name' => MetaAdsAiRoutes::AI_GUIDANCE_NAME,
            'module' => 'meta-ads',
            'description' => 'Grounded Meta Ads AI guidance over Findings, Evidence, and Brand Context.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(SkillRegistry::class)->registerRoot(
            'meta-ads',
            dirname(__DIR__, 2).'/resources/skills',
        );

        $this->app->make(AgentProfileRegistry::class)->register(MetaAdsAnalyst::definition());
    }
}
