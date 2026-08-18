<?php

namespace MoxDop\GoogleBusinessProfile\Providers;

use App\Contracts\GbpOperatorWorkspace as GbpOperatorWorkspaceContract;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\GoogleBusinessProfile\Agents\GbpLocalPresenceAnalyst;
use MoxDop\GoogleBusinessProfile\Ai\GbpAiRoutes;
use MoxDop\GoogleBusinessProfile\Collection\GbpLocationBoundCollector;
use MoxDop\GoogleBusinessProfile\Workspace\OperatorGbpWorkspace;

class GoogleBusinessProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.google-business-profile.loaded', true);
        $this->app->singleton(GbpOperatorWorkspaceContract::class, OperatorGbpWorkspace::class);
    }

    public function boot(): void
    {
        $this->app->make(BoundCollectorRegistry::class)
            ->register($this->app->make(GbpLocationBoundCollector::class));

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GBP_AI_GUIDANCE,
            'name' => GbpAiRoutes::AI_GUIDANCE_NAME,
            'module' => 'google-business-profile',
            'description' => 'Grounded GBP local-presence guidance over Findings, Evidence, and Brand Context. Designed — execution pipeline not claimed.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(SkillRegistry::class)->registerRoot(
            'google-business-profile',
            dirname(__DIR__, 2).'/resources/skills',
        );

        $this->app->make(AgentProfileRegistry::class)->register(GbpLocalPresenceAnalyst::definition());
    }
}
