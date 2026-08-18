<?php

namespace App\Providers;

use App\Agents\SalesProspectIntelligenceAnalyst;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SALES_PROSPECT_INTELLIGENCE,
            'name' => 'Sales Prospect Intelligence',
            'module' => 'sales',
            'description' => 'Bounded advisory sales intelligence for inbound Prospects using observed public evidence and the canonical service catalog.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(SkillRegistry::class)->registerRoot(
            'sales',
            base_path('resources/skills'),
        );

        $this->app->make(AgentProfileRegistry::class)->register(
            SalesProspectIntelligenceAnalyst::definition(),
        );
    }
}
