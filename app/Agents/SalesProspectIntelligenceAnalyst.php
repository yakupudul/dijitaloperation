<?php

namespace App\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

/**
 * Sales-owned Prospect Intelligence Analyst (advisory only).
 */
final class SalesProspectIntelligenceAnalyst
{
    public const string SLUG = AgentProfileKeys::SALES_PROSPECT_INTELLIGENCE_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'Sales Prospect Intelligence Analyst';

    /**
     * @return list<string>
     */
    public static function skillSlugs(): array
    {
        return [
            'prospect-sales-intelligence',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'sales',
            purpose: 'Produce bounded advisory sales intelligence for inbound Prospects using observed public evidence and the canonical service catalog.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::SALES_PROSPECT_INTELLIGENCE,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'prospect_identity',
                'prospect_inquiry',
                'prospect_observed_evidence',
                'canonical_service_catalog',
            ],
            allowedOperations: [
                'summarize_observed_evidence',
                'infer_needs',
                'recommend_catalog_services',
            ],
            forbiddenOperations: [
                'change_prospect_status',
                'create_customer',
                'create_brand',
                'create_task',
                'send_outreach',
                'approve_recommendations',
                'fabricate_evidence',
                'access_credentials',
            ],
            outputContract: 'Structured sales intelligence with catalog service codes, evidence refs, and confidence.',
            successCriteria: [
                'Recommendations reference canonical ServiceDefinition codes only.',
                'Observed vs inferred content is clearly separated.',
                'Missing AI or evidence yields truthful unavailable state.',
            ],
        );
    }
}
