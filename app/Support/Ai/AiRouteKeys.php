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

    /** Faz 2: group discovered accounts into brand candidates + propose each candidate's sector (one call per batch). */
    public const string BRAND_CANDIDATES = 'brand.candidates';

    /** Faz 2: propose a brand's services from its own pages, matched to the sector catalog (one call). */
    public const string BRAND_SERVICES = 'brand.services';

    public const string GOOGLE_ADS_AD_COPY_DRAFT = 'google_ads.ad_copy_draft';

    public const string META_ADS_CREATIVE_DRAFT = 'meta_ads.creative_draft';

    public const string GBP_PROFILE_DRAFT = 'gbp.profile_draft';

    public const string MONTHLY_REPORT_COMMENTARY = 'reports.monthly_commentary';

    public const string AI_VISIBILITY_PROBE = 'intel.ai_visibility_probe';

    public const string GBP_REVIEW_REPLY = 'gbp.review_reply';

    public const string GBP_POST_DRAFT = 'gbp.post_draft';

    /** Faz 7: brand offerings vs Business Profile categories / services → missing services + category notes (one call). */
    public const string GBP_SERVICES_COMPARE = 'gbp.services_compare';

    /** Faz 7: proposed Business Profile description (≤ 750 characters) from brand memory, offerings and areas. */
    public const string GBP_DESCRIPTION = 'gbp.description';

    /** Faz 7: one Business Profile post from one page of the brand's site (link to the page). */
    public const string GBP_POST_FROM_PAGE = 'gbp.post_from_page';

    public const string INSIGHT_ADVISOR_EXPLAIN = 'insights.advisor_explain';

    public const string INSIGHT_SEARCH_TERM_TRIAGE = 'insights.search_term_triage';

    public const string INSIGHT_REVIEW_THEMES = 'insights.review_themes';

    public const string INSIGHT_ALERT_CAUSE = 'insights.alert_cause';

    public const string INSIGHT_LANDING_FIT = 'insights.landing_fit';

    public const string INSIGHT_CUSTOMER_BRIEF = 'insights.customer_brief';

    public const string INSIGHT_LEAD_SCORE = 'insights.lead_score';

    public const string INSIGHT_META_GEO = 'insights.meta_geo';

    public const string INSIGHT_TECHNICAL_TASKS = 'insights.technical_tasks';

    public const string SITE_FIX_VALUES = 'site_fixes.values';

    public const string SITE_FIX_LINKS = 'site_fixes.internal_links';

    public const string SITE_FIX_PAGE = 'site_fixes.page_writer';

    /** ADR-076: localize an article into another language of the site (title, slug, meta, body, internal links). */
    public const string CONTENT_LOCALIZE = 'content.localize';

    /** Faz 4: write one article of the content studio from an idea. */
    public const string CONTENT_ARTICLE = 'content.article';

    /** Faz 4: one batch of gap article ideas for chosen services (one call per batch, never per idea). */
    public const string CONTENT_IDEAS = 'content.ideas';

    /** Faz 3 "AI ile kural üret": selected queries → new filter basket terms + new matching keywords per service (one call). */
    public const string QUERIES_FILTER_RULES = 'queries.filter_rules';

    /** Faz 3 "AI ile kümele": one service's queries → clusters (same user need on the same page type), one call. */
    public const string QUERIES_CLUSTER = 'queries.cluster';

    /** Sorgu hattı: sector of every discovered account / website (batched). */
    public const string QUERIES_ASSET_SECTOR = 'queries.asset_sector';

    /** Brand workspace analysts (Step 3): one weekly / on-demand AI analysis per brand × channel. */
    public const string ANALYST_SEARCH = 'analyst.search';

    /** Reserved: registered when App\Services\Analyst\Maps\MapsAnalyst exists. */
    public const string ANALYST_MAPS = 'analyst.maps';

    /** Reserved: registered when App\Services\Analyst\GoogleAds\GoogleAdsAnalyst exists. */
    public const string ANALYST_GOOGLE_ADS = 'analyst.google_ads';

    /** Reserved: registered when App\Services\Analyst\Meta\MetaAnalyst exists. */
    public const string ANALYST_META = 'analyst.meta';

    public const string BRAIN_EMBEDDINGS = 'brain.embeddings';

    public const string BRAIN_ACCOUNT_MAPPING = 'brain.account_mapping';

    public const string BRAIN_QUERY_CLASSIFIER = 'brain.query_classifier';

    public const string BRAIN_CLUSTER_LABELS = 'brain.cluster_labels';

    public const string BRAIN_PAGE_FEATURES = 'brain.page_features';

    public const string BRAIN_CREATIVE_CLASSIFIER = 'brain.creative_classifier';
}
