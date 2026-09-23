<?php

namespace App\Providers;

use App\Agents\SalesIntentClassificationAnalyst;
use App\Agents\SalesProspectIntelligenceAnalyst;
use App\Ai\Agents\WhatsAppReplyAgent;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiDefaultSteps;
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
            'key' => WhatsAppReplyAgent::ROUTE,
            'name' => 'WhatsApp Reply Assistant',
            'module' => 'sales',
            'description' => 'Conversation-scoped Turkish reply drafts; human copy only, no sending.',
            'default_steps' => [[
                'provider' => AiProviderCatalog::OPENAI,
                'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
            ]],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SALES_PROSPECT_INTELLIGENCE,
            'name' => 'Sales Prospect Intelligence',
            'module' => 'sales',
            'description' => 'Bounded advisory sales intelligence for inbound Prospects using observed public evidence and the canonical service catalog.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SALES_INTENT_CLASSIFICATION,
            // Works on agency-wide / public text only: free-tier providers may be selected.
            'contains_client_data' => false,
            'name' => 'Sales Intent Classification',
            'module' => 'sales',
            'description' => 'Bounded purchase-intent classification for public Intent Signals using observed snippets and the canonical service catalog.',
            'default_steps' => AiDefaultSteps::publicData(),
        ]);

        $this->app->make(SkillRegistry::class)->registerRoot(
            'sales',
            base_path('resources/skills'),
        );

        $this->app->make(AgentProfileRegistry::class)->register(
            SalesProspectIntelligenceAnalyst::definition(),
        );

        $this->app->make(AgentProfileRegistry::class)->register(
            SalesIntentClassificationAnalyst::definition(),
        );
    }
}
