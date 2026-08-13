<?php

namespace MoxDop\Website\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

/**
 * Search Console Organic Search Analyst (product definition).
 *
 * Registered under the Website module while real GSC collection remains transitional /
 * Website-scoped. Status is designed — no live GSC AI execution pipeline claimed.
 */
final class GscOrganicSearchAnalyst
{
    public const string SLUG = AgentProfileKeys::GSC_ORGANIC_SEARCH_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'Search Console Organic Search Analyst';

    /**
     * @return list<string>
     */
    public static function skillSlugs(): array
    {
        return [
            'gsc-search-demand-review',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'website',
            purpose: 'Interpret bounded Search Console Evidence as Organic Demand & Search Intelligence without inventing rankings or live index mutations.',
            status: 'designed',
            aiRouteKey: AiRouteKeys::GSC_AI_GUIDANCE,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'brand_intelligence_context',
                'target_gsc_digital_asset',
                'related_website_digital_asset',
                'relevant_complete_gsc_runs',
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
                'gsc_mutations',
                'force_index_or_bulk_submit',
                'create_tasks',
                'approve_recommendations',
                'modify_agent_or_skills',
            ],
            outputContract: 'Structured GSC AI Guidance covering query/page momentum, ownership candidates, and indexing observations with provenance.',
            successCriteria: [
                'Average Position is never presented as one exact Google ranking or GBP geo-grid rank.',
                'Query rows are described as observed Search Console queries — never “all keywords people search”.',
                'No Live Test / Force Index / Bulk Submit claims.',
                'Outputs remain advisory; no provider writes.',
            ],
        );
    }
}
