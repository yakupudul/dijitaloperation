<?php

namespace MoxDop\GoogleAds\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

/**
 * Google Ads-owned operational Agent Profile (V1).
 */
final class GoogleAdsAnalyst
{
    public const string SLUG = AgentProfileKeys::GOOGLE_ADS_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'Google Ads Analyst';

    /**
     * @return list<string>
     */
    public static function skillSlugs(): array
    {
        return [
            'account-performance-audit',
            'campaign-performance-analysis',
            'search-query-analysis',
            'measurement-quality-review',
            'landing-page-alignment',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'google-ads',
            purpose: 'Analyze bounded normalized Google Ads Evidence and deterministic Findings using approved Google Ads Skills, then produce grounded operational AI Guidance.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::GOOGLE_ADS_AI_GUIDANCE,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'brand_intelligence_context',
                'target_google_ads_digital_asset',
                'relevant_complete_google_ads_runs',
                'relevant_active_findings',
                'supporting_normalized_evidence',
                'deterministic_recommendation_baseline',
                'assigned_eligible_skills',
            ],
            allowedOperations: [
                'analyze_bounded_context',
                'draft_ai_guidance',
                'cite_finding_and_evidence_ids',
            ],
            forbiddenOperations: [
                'access_credentials',
                'read_unrelated_customers_brands_assets',
                'arbitrary_database_access',
                'arbitrary_raw_provider_dumps',
                'external_platform_writes',
                'google_ads_mutations',
                'add_negative_keywords',
                'add_keywords',
                'change_bids_or_budgets',
                'create_tasks',
                'approve_recommendations',
                'modify_agent_or_skills',
            ],
            outputContract: 'Structured Google Ads AI Guidance with executive_summary, overall_priority, context_observations, and grounded finding_interpretations.',
            successCriteria: [
                'Every claim is grounded in supplied Findings/Evidence/Brand Context or explicitly marked uncertain.',
                'Missing Evidence never becomes invented metrics or fabricated search-term analysis.',
                'Platform conversions are never labeled as verified business profit/ROI without CRM linkage.',
                'Causal claims remain cautious (associated with / plausible contributor) unless Evidence supports causality.',
                'Outputs remain advisory; Recommendations stay human-gated; no Google Ads mutations.',
            ],
        );
    }
}
