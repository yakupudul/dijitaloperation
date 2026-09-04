<?php

namespace App\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

final class SearchIntelligenceAnalyst
{
    public const string SLUG = AgentProfileKeys::SEARCH_DEMAND_INTELLIGENCE_ANALYST;

    public const string VERSION = '1.2.0';

    public const string NAME = 'Search Intelligence Analyst';

    /** @return list<string> */
    public static function skillSlugs(): array
    {
        return [
            'search-query-generation',
            'search-query-classification',
            'search-demand-clustering',
            'page-relevance-analysis',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'search_demand',
            purpose: 'Generate, classify, cluster, and compare eligible Website page candidates for human review using bounded Brand, service, market, and observed page context.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::SEARCH_DEMAND_LIBRARIAN,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'canonical_service_catalog',
                'search_query_library',
                'brand_query_portfolio',
                'search_demand_clusters',
                'website_page_profiles',
                'gsc_query_page_observations',
                'serp_brand_url_observations',
                'operator_supplied_market_context',
            ],
            allowedOperations: [
                'generate_query_candidates',
                'classify_query_candidates',
                'suggest_service_alias',
                'suggest_location_pattern',
                'flag_branded_expression',
                'propose_query_clusters',
                'propose_cluster_moves_merges_and_splits',
                'propose_page_owner',
                'classify_page_relevance',
                'propose_content_type',
            ],
            forbiddenOperations: [
                'invent_search_metrics',
                'approve_candidates',
                'create_findings',
                'create_tasks',
                'publish_content',
                'perform_external_writes',
                'spend_provider_credits',
                'change_url_ownership',
                'redirect_or_delete_pages',
            ],
            outputContract: 'Structured candidate list with semantic fields, confidence, rationale, abstention, and provenance fingerprints.',
            successCriteria: [
                'Every suggestion remains pending until explicit human review.',
                'No search volume, ranking, traffic, or conversion metric is invented.',
                'Weak or ambiguous evidence produces abstention or low confidence.',
            ],
        );
    }
}
