<?php

namespace MoxDop\MetaAds\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

/**
 * Meta Ads-owned operational Agent Profile (V1).
 */
final class MetaAdsAnalyst
{
    public const string SLUG = AgentProfileKeys::META_ADS_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'Meta Ads Analyst';

    /**
     * @return list<string>
     */
    public static function skillSlugs(): array
    {
        return [
            'account-performance-audit',
            'campaign-performance-analysis',
            'adset-delivery-analysis',
            'ad-creative-performance-analysis',
            'measurement-result-review',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'meta-ads',
            purpose: 'Analyze bounded normalized Meta Ads Evidence and deterministic Findings using approved Meta Ads Skills, then produce grounded operational AI Guidance.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::META_ADS_AI_GUIDANCE,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'brand_intelligence_context',
                'target_meta_ads_digital_asset',
                'relevant_complete_meta_ads_runs',
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
                'meta_ads_mutations',
                'lead_retrieval',
                'pause_ads',
                'change_budgets',
                'change_bids',
                'publish_creatives',
                'create_tasks',
                'approve_recommendations',
                'modify_agent_or_skills',
            ],
            outputContract: 'Structured Meta Ads AI Guidance with executive_summary, overall_priority, context_observations, and grounded finding_interpretations.',
            successCriteria: [
                'Every claim is grounded in supplied Findings/Evidence/Brand Context or explicitly marked uncertain.',
                'Missing Evidence never becomes invented metrics or fabricated hierarchy/creative analysis.',
                'Platform actions/results are never labeled as verified qualified leads, sales, or profit without CRM linkage.',
                'Causal claims remain cautious (associated with / plausible contributor) unless Evidence supports causality.',
                'Outputs remain advisory; Recommendations stay human-gated; no Meta Ads mutations.',
            ],
        );
    }
}
