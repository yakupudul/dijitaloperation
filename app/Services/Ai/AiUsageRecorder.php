<?php

namespace App\Services\Ai;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Events\AgentPrompted;
use Throwable;

/**
 * Records token usage and cost of every laravel/ai agent call, whatever route made it.
 * Never throws: accounting must not break an AI workflow.
 */
final class AiUsageRecorder
{
    /** @var array<string, string> agent class basename => AI route key */
    private const array AGENT_ROUTES = [
        'SeoTaskContentPlannerAgent' => AiRouteKeys::SEO_TASKS_CONTENT_PLANNER,
        'SeoSiteUnderstandingAgent' => AiRouteKeys::SEO_TASKS_SITE_UNDERSTANDING,
        'BrandSetupAgent' => AiRouteKeys::BRAND_SETUP,
        'SearchDemandLibrarianAgent' => AiRouteKeys::SEARCH_DEMAND_LIBRARIAN,
        'SearchDemandClusteringAgent' => AiRouteKeys::SEARCH_DEMAND_CLUSTERING,
        'SearchDemandPageRelevanceAgent' => AiRouteKeys::SEARCH_DEMAND_PAGE_RELEVANCE,
        'SearchDemandCompetitiveIntelligenceAgent' => AiRouteKeys::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE,
        'SearchDemandWebsiteImprovementAgent' => AiRouteKeys::SEARCH_DEMAND_WEBSITE_IMPROVEMENT,
        'SearchDemandChangeVerificationAgent' => AiRouteKeys::SEARCH_DEMAND_CHANGE_VERIFICATION,
        'SalesIntentClassificationAgent' => AiRouteKeys::SALES_INTENT_CLASSIFICATION,
        'SalesProspectIntelligenceAgent' => AiRouteKeys::SALES_PROSPECT_INTELLIGENCE,
        'WebsiteDiscoveryContextAgent' => AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT,
        'GoogleAdsAdCopyAgent' => AiRouteKeys::GOOGLE_ADS_AD_COPY_DRAFT,
        'MetaAdsCreativeAgent' => AiRouteKeys::META_ADS_CREATIVE_DRAFT,
        'GbpProfileAgent' => AiRouteKeys::GBP_PROFILE_DRAFT,
    ];

    public function __construct(private readonly AiPricing $pricing) {}

    public function handle(AgentPrompted $event): void
    {
        try {
            if (! Schema::hasTable('ai_usage_records')) {
                return;
            }
            $agent = class_basename($event->prompt->agent);
            $usage = $event->response->usage;
            $provider = (string) ($event->response->meta->provider ?? 'unknown');
            $model = (string) ($event->response->meta->model ?? 'unknown');
            $routeKey = self::AGENT_ROUTES[$agent] ?? Context::getHidden('ai_route_key');

            DB::table('ai_usage_records')->insert([
                'route_key' => is_string($routeKey) ? $routeKey : null,
                'agent' => mb_substr($agent, 0, 190),
                'provider' => mb_substr($provider, 0, 48),
                'model' => mb_substr($model, 0, 190),
                'input_tokens' => max(0, $usage->promptTokens),
                'output_tokens' => max(0, $usage->completionTokens),
                'cache_read_tokens' => max(0, $usage->cacheReadInputTokens),
                'cache_write_tokens' => max(0, $usage->cacheWriteInputTokens),
                'cost_usd' => $this->pricing->cost($provider, $model, $usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens, $usage->cacheWriteInputTokens),
                'invocation_id' => mb_substr($event->invocationId, 0, 64),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('AI usage could not be recorded.', ['error' => $exception->getMessage()]);
        }
    }
}
