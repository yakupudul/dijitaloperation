<?php

namespace MoxDop\Website\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

/**
 * Website-owned operational Agent Profile (V1).
 */
final class WebsiteSeoAnalyst
{
    public const string SLUG = AgentProfileKeys::WEBSITE_SEO_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'Website SEO Analyst';

    /**
     * @return list<string>
     */
    public static function skillSlugs(): array
    {
        return [
            'technical-seo-analysis',
            'indexability-analysis',
            'metadata-consistency',
            'search-console-analysis',
            'keyword-opportunity-analysis',
            'recommendation-framing',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'website',
            purpose: 'Interpret bounded Website Evidence, Findings, and Brand Context using approved Website Skills to produce grounded Website AI Guidance.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::WEBSITE_AI_GUIDANCE,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'brand_intelligence_context',
                'target_website_digital_asset',
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
                'create_tasks',
                'approve_recommendations',
                'modify_agent_or_skills',
            ],
            outputContract: 'Structured Website AI Guidance with executive_summary, overall_priority, context_observations, and grounded finding_interpretations.',
            successCriteria: [
                'Every claim is grounded in supplied Findings/Evidence/Brand Context or explicitly marked uncertain.',
                'Missing Evidence never becomes invented metrics.',
                'Outputs remain advisory; Recommendations stay human-gated.',
                'No credentials or unrelated Brand/Asset data appear in context.',
            ],
        );
    }
}
