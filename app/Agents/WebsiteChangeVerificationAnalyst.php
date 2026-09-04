<?php

namespace App\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

final class WebsiteChangeVerificationAnalyst
{
    public const string SLUG = AgentProfileKeys::SEARCH_DEMAND_CHANGE_VERIFICATION_ANALYST;

    public const string VERSION = '1.0.0';

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: 'Website Change Verification Analyst',
            module: 'search_demand',
            purpose: 'Compare bounded, stored before-and-after page evidence and propose a semantic change verification for human review.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::SEARCH_DEMAND_CHANGE_VERIFICATION,
            skillSlugs: ['website-change-verification'],
            allowedDataScope: [
                'approved_search_demand_improvement_proposal',
                'applied_change_record',
                'stored_before_and_after_html_observations',
                'deterministic_technical_comparison',
            ],
            allowedOperations: [
                'compare_stored_page_content',
                'verify_intended_semantic_change',
                'propose_finding_state',
                'explain_evidence_and_abstention',
            ],
            forbiddenOperations: [
                'browse_or_collect_external_pages',
                'invent_metrics_or_causal_attribution',
                'change_canonical_finding_or_task_outcome',
                'publish_or_mutate_websites',
                'perform_external_writes',
            ],
            outputContract: 'Review-only semantic before/after verification with observed change, intended-change match, Finding state, evidence explanation, confidence, caveats, and abstention.',
            successCriteria: [
                'Every conclusion is grounded in supplied stored before-and-after evidence.',
                'Metric movement is never attributed causally to the applied change.',
                'A human must accept the proposed Outcome before canonical Task state changes.',
            ],
        );
    }
}
