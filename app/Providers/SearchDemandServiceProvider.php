<?php

namespace App\Providers;

use App\Agents\SearchIntelligenceAnalyst;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\ServiceProvider;

class SearchDemandServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SEARCH_DEMAND_LIBRARIAN,
            'name' => 'Search Demand Librarian',
            'module' => 'search_demand',
            'description' => 'Bounded generation and semantic classification of search-query candidates behind explicit human approval.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SEARCH_DEMAND_CLUSTERING,
            'name' => 'Search Demand Clustering',
            'module' => 'search_demand',
            'description' => 'Human-reviewed semantic clustering and cluster-maintenance proposals for Brand Query Portfolios.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(SkillRegistry::class)->registerRoot(
            'search_demand',
            base_path('resources/search-demand-skills'),
        );

        $this->app->make(AgentProfileRegistry::class)->register(
            SearchIntelligenceAnalyst::definition(),
        );
    }
}
