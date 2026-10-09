<?php

namespace App\Services\Ai;

use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Throwable;

/**
 * Records token usage, cost, duration, status and prompt version of every laravel/ai agent call, whatever route made
 * it (failed-over attempts are recorded as failed).
 * Never throws: accounting must not break an AI workflow.
 */
final class AiUsageRecorder
{
    /** @var array<string, string> agent class basename => AI route key */
    private const array AGENT_ROUTES = [
        'SeoTaskContentPlannerAgent' => AiRouteKeys::SEO_TASKS_CONTENT_PLANNER,
        'SeoSiteUnderstandingAgent' => AiRouteKeys::SEO_TASKS_SITE_UNDERSTANDING,
        'BrandSetupAgent' => AiRouteKeys::BRAND_SETUP,
        'BrandCandidateAgent' => AiRouteKeys::BRAND_CANDIDATES,
        'BrandServiceAgent' => AiRouteKeys::BRAND_SERVICES,
        'SearchDemandLibrarianAgent' => AiRouteKeys::SEARCH_DEMAND_LIBRARIAN,
        'SearchDemandClusteringAgent' => AiRouteKeys::SEARCH_DEMAND_CLUSTERING,
        'SearchDemandPageRelevanceAgent' => AiRouteKeys::SEARCH_DEMAND_PAGE_RELEVANCE,
        'SearchDemandCompetitiveIntelligenceAgent' => AiRouteKeys::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE,
        'SearchDemandWebsiteImprovementAgent' => AiRouteKeys::SEARCH_DEMAND_WEBSITE_IMPROVEMENT,
        'SearchDemandChangeVerificationAgent' => AiRouteKeys::SEARCH_DEMAND_CHANGE_VERIFICATION,
        'SalesProspectIntelligenceAgent' => AiRouteKeys::SALES_PROSPECT_INTELLIGENCE,
        'GoogleAdsAdCopyAgent' => AiRouteKeys::GOOGLE_ADS_AD_COPY_DRAFT,
        'MetaAdsCreativeAgent' => AiRouteKeys::META_ADS_CREATIVE_DRAFT,
        'GbpProfileAgent' => AiRouteKeys::GBP_PROFILE_DRAFT,
        'MonthlyReportCommentaryAgent' => AiRouteKeys::MONTHLY_REPORT_COMMENTARY,
        'AiVisibilityProbeAgent' => AiRouteKeys::AI_VISIBILITY_PROBE,
        'ReviewReplyAgent' => AiRouteKeys::GBP_REVIEW_REPLY,
        'GbpServicesCompareAgent' => AiRouteKeys::GBP_SERVICES_COMPARE,
        'GbpDescriptionAgent' => AiRouteKeys::GBP_DESCRIPTION,
        'GbpProfilePlanAgent' => AiRouteKeys::GBP_PROFILE_PLAN,
        'GbpPostQueueAgent' => AiRouteKeys::GBP_POST_QUEUE,
        'GbpBranchPageAgent' => AiRouteKeys::GBP_BRANCH_PAGE,
        'BrandCareAgent' => AiRouteKeys::BRAND_CARE,
        'BrandChiefAgent' => AiRouteKeys::BRAND_CHIEF,
        'GbpPostFromPageAgent' => AiRouteKeys::GBP_POST_FROM_PAGE,
        'MetaCreativesAgent' => AiRouteKeys::META_CREATIVES,
        'MetaStructureAgent' => AiRouteKeys::META_STRUCTURE,
        'MetaLandingAgent' => AiRouteKeys::META_LANDING,
        'MetaCampaignServicesAgent' => AiRouteKeys::META_CAMPAIGN_SERVICES,
        'MetaStrategyPlanAgent' => AiRouteKeys::META_STRATEGY_PLAN,
        'GoogleAdsSearchTermsAgent' => AiRouteKeys::GOOGLE_ADS_SEARCH_TERMS,
        'GoogleAdsStructureAgent' => AiRouteKeys::GOOGLE_ADS_STRUCTURE,
        'GoogleAdsAdTextsAgent' => AiRouteKeys::GOOGLE_ADS_AD_TEXTS,
        'AdvisorExplainAgent' => AiRouteKeys::INSIGHT_ADVISOR_EXPLAIN,
        'AlertCauseAgent' => AiRouteKeys::INSIGHT_ALERT_CAUSE,
        'CustomerBriefAgent' => AiRouteKeys::INSIGHT_CUSTOMER_BRIEF,
        'LeadScoreAgent' => AiRouteKeys::INSIGHT_LEAD_SCORE,
        'TechnicalTasksAgent' => AiRouteKeys::INSIGHT_TECHNICAL_TASKS,
        'SiteFixValuesAgent' => AiRouteKeys::SITE_FIX_VALUES,
        'InternalLinkAgent' => AiRouteKeys::SITE_FIX_LINKS,
        'PageWriterAgent' => AiRouteKeys::SITE_FIX_PAGE,
        'ContentLocalizerAgent' => AiRouteKeys::CONTENT_LOCALIZE,
        'ArticleWriterAgent' => AiRouteKeys::CONTENT_ARTICLE,
        'ContentIdeasAgent' => AiRouteKeys::CONTENT_IDEAS,
        'SeoFieldsBatchAgent' => AiRouteKeys::SITE_SEO_FIELDS_BATCH,
        'ContentRecipeAgent' => AiRouteKeys::SITE_CONTENT_RECIPE,
        'ForbiddenTermsAgent' => AiRouteKeys::COMPLIANCE_FORBIDDEN_TERMS,
        'AssetSectorAgent' => AiRouteKeys::QUERIES_ASSET_SECTOR,
        'QueryClusterAgent' => AiRouteKeys::QUERIES_CLUSTER,
        'QueryTriageAgent' => AiRouteKeys::QUERIES_TRIAGE,
        'QueryClusterReviewAgent' => AiRouteKeys::QUERIES_CLUSTER_REVIEW,
        'QueryRulesAgent' => AiRouteKeys::QUERIES_FILTER_RULES,
        'QueryPlanSectorsAgent' => AiRouteKeys::QUERIES_PLAN_SECTORS,
        'QueryPlanServicesAgent' => AiRouteKeys::QUERIES_PLAN_SERVICES,
        'QueryPlanFiltersAgent' => AiRouteKeys::QUERIES_PLAN_FILTERS,
        'QueryFilterScanAgent' => AiRouteKeys::QUERIES_SCAN_FILTERS,
        'QueryAssignServicesAgent' => AiRouteKeys::QUERIES_ASSIGN_SERVICES,
        'CompetitorClassifyAgent' => AiRouteKeys::COMPETITORS_CLASSIFY,
        'CompetitorAnalyzeAgent' => AiRouteKeys::COMPETITORS_ANALYZE,
        'BacklinkSourcesAgent' => AiRouteKeys::BACKLINKS_SOURCES,
    ];

    /** Hidden context flag of a prompt trial run (PromptTrial). */
    public const string TRIAL_CONTEXT = 'ai_prompt_trial';

    /** Route key the usage record of a prompt trial carries. */
    public const string TRIAL_ROUTE = 'prompt_trial';

    /** Longest stored run input (characters). */
    public const int INPUT_MAX = 200000;

    private static ?bool $keepsInput = null;

    /** @var array<int, float> agent object id => start time of its current attempt (hrtime ms) */
    private static array $starts = [];

    public function __construct(private readonly AiPricing $pricing) {}

    public function started(PromptingAgent $event): void
    {
        self::$starts[spl_object_id($event->prompt->agent)] = hrtime(true) / 1e6;
    }

    /** A provider attempt that failed over to the next provider: recorded as a failed run (no tokens, no cost). */
    public function failed(AgentFailedOver $event): void
    {
        try {
            if (! Schema::hasTable('ai_usage_records')) {
                return;
            }
            $agent = class_basename($event->agent);
            $routeKey = self::routeKeyFor($event->agent);
            DB::table('ai_usage_records')->insert([
                'route_key' => is_string($routeKey) ? $routeKey : null,
                'prompt_version_id' => $this->promptVersionId($event->agent),
                'agent' => mb_substr($agent, 0, 190),
                'provider' => mb_substr($event->provider->name(), 0, 48),
                'model' => mb_substr($event->model, 0, 190),
                'cost_usd' => 0,
                'duration_ms' => $this->duration($event->agent),
                'status' => 'failed',
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('AI failover could not be recorded.', ['error' => $exception->getMessage()]);
        }
    }

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
            $routeKey = self::routeKeyFor($event->prompt->agent);
            $trial = Context::getHidden(self::TRIAL_CONTEXT) === true;
            // A prompt trial ("Örnekte dene") costs like any run but is not a run of the operation.
            $routeKey = $trial ? self::TRIAL_ROUTE : $routeKey;

            DB::table('ai_usage_records')->insert([
                'route_key' => is_string($routeKey) ? $routeKey : null,
                'prompt_version_id' => $this->promptVersionId($event->prompt->agent),
                'agent' => mb_substr($agent, 0, 190),
                'provider' => mb_substr($provider, 0, 48),
                'model' => mb_substr($model, 0, 190),
                'input_tokens' => max(0, $usage->promptTokens),
                'output_tokens' => max(0, $usage->completionTokens),
                'cache_read_tokens' => max(0, $usage->cacheReadInputTokens),
                'cache_write_tokens' => max(0, $usage->cacheWriteInputTokens),
                'cost_usd' => $this->billedCost($event->invocationId, $this->pricing->cost($provider, $model, $usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens, $usage->cacheWriteInputTokens)),
                'invocation_id' => mb_substr($event->invocationId, 0, 64),
                'duration_ms' => $this->duration($event->prompt->agent),
                'status' => 'ok',
                'created_at' => now(),
            ] + (! $trial && $event->prompt->agent instanceof RegistryPrompted && self::keepsInput()
                ? ['input_text' => mb_substr($event->prompt->prompt, 0, self::INPUT_MAX)] : []));
        } catch (Throwable $exception) {
            Log::warning('AI usage could not be recorded.', ['error' => $exception->getMessage()]);
        }
    }

    private static function keepsInput(): bool
    {
        return self::$keepsInput ??= Schema::hasColumn('ai_usage_records', 'input_text');
    }

    /**
     * The AI route key (= prompt operation) of an agent call: the fixed agent map, else the operation a
     * registry-prompted agent names itself, else the route the caller resolved (hidden context).
     */
    public static function routeKeyFor(object $agent): ?string
    {
        $routeKey = self::AGENT_ROUTES[class_basename($agent)]
            ?? ($agent instanceof RegistryPrompted ? $agent->promptOperation() : null)
            ?? Context::getHidden('ai_route_key');

        return is_string($routeKey) && $routeKey !== '' ? $routeKey : null;
    }

    /** The billed cost the call list wrote (OpenAI ücretsiz kota applied there), else the list price. */
    private function billedCost(string $invocationId, ?float $listCost): ?float
    {
        if (! Schema::hasColumn('ai_live_operations', 'list_cost_usd')) {
            return $listCost;
        }
        $row = DB::table('ai_live_operations')->where('invocation_id', mb_substr($invocationId, 0, 64))->where('kind', 'call')
            ->whereNotNull('list_cost_usd')->orderByDesc('id')->first(['cost_usd']);

        return $row !== null && $row->cost_usd !== null ? (float) $row->cost_usd : $listCost;
    }

    private function promptVersionId(object $agent): ?int
    {
        try {
            return $agent instanceof RegistryPrompted ? $agent->promptVersionId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function duration(object $agent): ?int
    {
        $start = self::$starts[spl_object_id($agent)] ?? null;
        unset(self::$starts[spl_object_id($agent)]);

        return $start === null ? null : max(0, (int) round(hrtime(true) / 1e6 - $start));
    }
}
