<?php

namespace MoxDop\GoogleBusinessProfile\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

/**
 * Google Business Profile Local Presence Analyst (product definition).
 *
 * Status is designed: route + profile are registered for Control Plane / Skills,
 * but no live GBP AI execution pipeline is claimed in this milestone.
 */
final class GbpLocalPresenceAnalyst
{
    public const string SLUG = AgentProfileKeys::GBP_LOCAL_PRESENCE_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'GBP Local Presence Analyst';

    /**
     * @return list<string>
     */
    public static function skillSlugs(): array
    {
        return [
            'local-presence-audit',
            'review-pulse-analysis',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'google-business-profile',
            purpose: 'Analyze bounded GBP Evidence (profile consistency, local visibility samples, reviews, customer actions) and produce grounded local-presence guidance for human review.',
            status: 'designed',
            aiRouteKey: AiRouteKeys::GBP_AI_GUIDANCE,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'brand_intelligence_context',
                'target_gbp_digital_asset',
                'relevant_complete_gbp_runs',
                'relevant_active_findings',
                'supporting_normalized_evidence',
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
                'external_platform_writes',
                'gbp_mutations',
                'auto_reply_to_reviews',
                'create_tasks',
                'approve_recommendations',
                'modify_agent_or_skills',
            ],
            outputContract: 'Structured GBP AI Guidance with executive_summary, overall_priority, consistency observations, and grounded finding_interpretations. Never claims a local SEO score.',
            successCriteria: [
                'Every claim is grounded in supplied Findings/Evidence/Brand Context or explicitly marked uncertain.',
                'Map/rank samples are treated as observational fixtures or provider samples — not invented matrices.',
                'Review response drafts remain advisory; never auto-sent.',
                'Outputs remain advisory; Recommendations stay human-gated; no GBP mutations.',
            ],
        );
    }
}
