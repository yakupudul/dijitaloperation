<?php

namespace App\Agents;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Ai\AiRouteKeys;

final class SalesIntentClassificationAnalyst
{
    public const string SLUG = AgentProfileKeys::SALES_INTENT_CLASSIFICATION_ANALYST;

    public const string VERSION = '1.0.0';

    public const string NAME = 'Sales Intent Classification Analyst';

    /**
     * @return list<string>
     */
    public static function skillSlugs(): array
    {
        return [
            'intent-sales-qualification',
        ];
    }

    public static function definition(): AgentProfileDefinition
    {
        return new AgentProfileDefinition(
            slug: self::SLUG,
            version: self::VERSION,
            name: self::NAME,
            module: 'sales',
            purpose: 'Classify whether observed public search text indicates purchase intent for canonical agency services.',
            status: 'operational',
            aiRouteKey: AiRouteKeys::SALES_INTENT_CLASSIFICATION,
            skillSlugs: self::skillSlugs(),
            allowedDataScope: [
                'search_snippet',
                'fetched_source_excerpt',
                'canonical_service_catalog',
            ],
            allowedOperations: [
                'classify_purchase_intent',
                'map_catalog_service',
            ],
            forbiddenOperations: [
                'identify_anonymous_people',
                'generate_outreach',
                'convert_prospect',
                'approve_sales_decisions',
                'fabricate_source_content',
                'access_credentials',
            ],
            outputContract: 'Structured intent classification with confidence, reason, and optional ServiceDefinition code.',
            successCriteria: [
                'Informational queries are not treated as high-intent leads.',
                'Unknown identity stays unknown.',
                'Missing AI yields unavailable classification, not invented scores.',
            ],
        );
    }
}
