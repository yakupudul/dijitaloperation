<?php

namespace App\Support\Ai;

/**
 * Stable AI route keys registered by modules and consumed by Control Plane UI.
 * Keys are shared identifiers — route meaning remains owned by the registering module.
 */
final class AiRouteKeys
{
    public const string WEBSITE_AI_GUIDANCE = 'website.ai_guidance';

    public const string WEBSITE_DISCOVERY_CONTEXT = 'website.discovery_context';

    public const string GOOGLE_ADS_AI_GUIDANCE = 'google_ads.ai_guidance';

    public const string META_ADS_AI_GUIDANCE = 'meta_ads.ai_guidance';

    public const string GBP_AI_GUIDANCE = 'gbp.ai_guidance';

    public const string GA4_AI_GUIDANCE = 'ga4.ai_guidance';

    public const string GSC_AI_GUIDANCE = 'gsc.ai_guidance';

    public const string SALES_PROSPECT_INTELLIGENCE = 'sales.prospect_intelligence';

    public const string SALES_INTENT_CLASSIFICATION = 'sales.intent_classification';

    public const string SEARCH_DEMAND_LIBRARIAN = 'search_demand.librarian';

    public const string SEARCH_DEMAND_CLUSTERING = 'search_demand.clustering';
}
