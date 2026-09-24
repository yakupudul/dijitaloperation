<?php

namespace App\Support\Ai;

/**
 * Stable AI route keys registered by modules and consumed by Control Plane UI.
 * Keys are shared identifiers — route meaning remains owned by the registering module.
 */
final class AiRouteKeys
{
    public const string WEBSITE_DISCOVERY_CONTEXT = 'website.discovery_context';

    public const string SALES_PROSPECT_INTELLIGENCE = 'sales.prospect_intelligence';

    public const string SALES_INTENT_CLASSIFICATION = 'sales.intent_classification';

    public const string SEARCH_DEMAND_LIBRARIAN = 'search_demand.librarian';

    public const string SEARCH_DEMAND_CLUSTERING = 'search_demand.clustering';

    public const string SEARCH_DEMAND_PAGE_RELEVANCE = 'search_demand.page_relevance';

    public const string SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE = 'search_demand.competitive_intelligence';

    public const string SEARCH_DEMAND_WEBSITE_IMPROVEMENT = 'search_demand.website_improvement';

    public const string SEARCH_DEMAND_CHANGE_VERIFICATION = 'search_demand.change_verification';

    public const string SEO_TASKS_CONTENT_PLANNER = 'seo_tasks.content_planner';

    public const string SEO_TASKS_SITE_UNDERSTANDING = 'seo_tasks.site_understanding';

    public const string BRAND_SETUP = 'brand_setup.assistant';

    public const string GOOGLE_ADS_AD_COPY_DRAFT = 'google_ads.ad_copy_draft';

    public const string META_ADS_CREATIVE_DRAFT = 'meta_ads.creative_draft';

    public const string GBP_PROFILE_DRAFT = 'gbp.profile_draft';

    public const string MONTHLY_REPORT_COMMENTARY = 'reports.monthly_commentary';

    public const string AI_VISIBILITY_PROBE = 'intel.ai_visibility_probe';

    public const string GBP_REVIEW_REPLY = 'gbp.review_reply';

    public const string INSIGHT_ADVISOR_EXPLAIN = 'insights.advisor_explain';

    public const string INSIGHT_SEARCH_TERM_TRIAGE = 'insights.search_term_triage';

    public const string INSIGHT_REVIEW_THEMES = 'insights.review_themes';

    public const string INSIGHT_ALERT_CAUSE = 'insights.alert_cause';

    public const string INSIGHT_LANDING_FIT = 'insights.landing_fit';

    public const string INSIGHT_CUSTOMER_BRIEF = 'insights.customer_brief';

    public const string INSIGHT_LEAD_SCORE = 'insights.lead_score';

    public const string INSIGHT_TECHNICAL_TASKS = 'insights.technical_tasks';
}
