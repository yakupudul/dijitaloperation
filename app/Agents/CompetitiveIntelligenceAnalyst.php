<?php

namespace App\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

final class CompetitiveIntelligenceAnalyst
{
    public const string SLUG = AgentProfileKeys::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'Competitive Intelligence Analyst';

    /** @return list<string> */
    public static function skillSlugs(): array
    {
        return ['competitive-page-analysis'];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'search_demand',
            purpose: 'Compare bounded competitor-page observations with one human-verified Brand page and produce review-only, evidence-grounded differentiation proposals.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'search_demand_clusters',
                'brand_query_portfolio',
                'verified_page_ownership',
                'website_html_snapshots',
                'approved_competitor_library',
                'competitor_page_observations',
            ],
            allowedOperations: [
                'classify_competitor_type',
                'classify_competitor_page_intent',
                'extract_topics_and_user_questions',
                'compare_brand_and_competitor_coverage',
                'identify_local_trust_signals',
                'propose_differentiation_for_human_review',
                'abstain_when_evidence_is_weak',
            ],
            forbiddenOperations: [
                'browse_or_collect_external_pages',
                'spend_provider_credits_without_operator_action',
                'invent_rank_volume_traffic_or_conversion_metrics',
                'mutate_competitor_truth',
                'change_url_ownership',
                'create_findings_recommendations_or_tasks',
                'copy_competitor_content',
                'publish_content',
                'perform_external_writes',
            ],
            outputContract: 'Structured page analyses and portfolio-level differentiation themes with stable evidence IDs, confidence, abstention, provenance fingerprints, and a human-review state.',
            successCriteria: [
                'Every conclusion is traceable to supplied Brand and competitor page observations.',
                'Coverage is expressed as user needs and questions, never as a word-count contest.',
                'No classification or operational truth changes without a separate human action.',
            ],
        );
    }
}
