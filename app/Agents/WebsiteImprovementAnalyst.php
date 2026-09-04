<?php

namespace App\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

final class WebsiteImprovementAnalyst
{
    public const string SLUG = AgentProfileKeys::SEARCH_DEMAND_WEBSITE_IMPROVEMENT_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'Website Improvement Analyst';

    /** @return list<string> */
    public static function skillSlugs(): array
    {
        return ['website-improvement-planning'];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'search_demand',
            purpose: 'Turn approved, bounded search-demand analysis into evidence-linked semantic Finding and Recommendation drafts for explicit human approval.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::SEARCH_DEMAND_WEBSITE_IMPROVEMENT,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'search_demand_clusters',
                'verified_page_ownership',
                'approved_competitive_page_analyses',
                'stored_brand_page_snapshot',
                'page_relevance_signals',
            ],
            allowedOperations: [
                'synthesize_approved_analysis',
                'propose_semantic_findings',
                'propose_action_type',
                'draft_content_brief',
                'explain_evidence_confidence_and_verification',
                'abstain_when_evidence_is_weak',
            ],
            forbiddenOperations: [
                'browse_or_collect_external_pages',
                'use_unapproved_competitive_analysis',
                'invent_rank_volume_traffic_or_conversion_metrics',
                'create_canonical_findings_without_human_approval',
                'create_tasks',
                'publish_or_mutate_websites',
                'perform_external_writes',
            ],
            outputContract: 'Review-only semantic Finding and Recommendation proposals with action type, content brief, stable evidence references, confidence, rationale, verification steps, abstention, and provenance signatures.',
            successCriteria: [
                'Every proposal references only supplied approved analysis and stored page evidence.',
                'Insufficient evidence is represented explicitly rather than filled with assumptions.',
                'Canonical Finding and Recommendation creation is gated by a separate human action.',
            ],
        );
    }
}
