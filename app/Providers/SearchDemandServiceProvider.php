<?php

namespace App\Providers;

use App\Agents\CompetitiveIntelligenceAnalyst;
use App\Agents\SearchIntelligenceAnalyst;
use App\Agents\WebsiteImprovementAnalyst;
use App\Agents\WebsiteChangeVerificationAnalyst;
use App\Contracts\SearchDemand\SearchDemandSerpEnrichmentAdapter;
use App\Services\SearchDemand\DataForSeoSearchDemandEnrichmentAdapter;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\ServiceProvider;

class SearchDemandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            SearchDemandSerpEnrichmentAdapter::class,
            DataForSeoSearchDemandEnrichmentAdapter::class,
        );
    }

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

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SEARCH_DEMAND_PAGE_RELEVANCE,
            'name' => 'Search Demand Page Relevance',
            'module' => 'search_demand',
            'description' => 'Human-reviewed page-owner and content-type proposals over technically eligible Website page candidates.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE,
            'name' => 'Search Demand Competitive Intelligence',
            'module' => 'search_demand',
            'description' => 'Evidence-bounded competitor-page and verified Brand-page comparison with review-only differentiation proposals.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SEARCH_DEMAND_WEBSITE_IMPROVEMENT,
            'name' => 'Search Demand Website Improvement',
            'module' => 'search_demand',
            'description' => 'Human-gated semantic Finding and Recommendation proposals over approved competitive analysis and verified Brand-page evidence.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SEARCH_DEMAND_CHANGE_VERIFICATION,
            'name' => 'Search Demand Change Verification',
            'module' => 'search_demand',
            'description' => 'Human-reviewed semantic verification of stored before-and-after Website change evidence.',
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
        $this->app->make(AgentProfileRegistry::class)->register(
            CompetitiveIntelligenceAnalyst::definition(),
        );
        $this->app->make(AgentProfileRegistry::class)->register(
            WebsiteImprovementAnalyst::definition(),
        );
        $this->app->make(AgentProfileRegistry::class)->register(
            WebsiteChangeVerificationAnalyst::definition(),
        );
    }
}
