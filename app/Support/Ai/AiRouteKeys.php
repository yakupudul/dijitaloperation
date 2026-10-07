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

    /** Faz 5: search terms → intent / service fit per term + negatives (match type, scope, useful queries it could block). */
    public const string GOOGLE_ADS_SEARCH_TERMS = 'google_ads.search_terms';

    /** Faz 5: campaign / ad group structure and budget split by the main services + experiment plan. */
    public const string GOOGLE_ADS_STRUCTURE = 'google_ads.structure';

    /** Faz 5: one responsive search ad (headlines ≤ 30, descriptions ≤ 90) + landing page for one ad group. */
    public const string GOOGLE_ADS_AD_TEXTS = 'google_ads.ad_texts';

    public const string META_ADS_CREATIVE_DRAFT = 'meta_ads.creative_draft';

    public const string GBP_PROFILE_DRAFT = 'gbp.profile_draft';

    public const string MONTHLY_REPORT_COMMENTARY = 'reports.monthly_commentary';

    public const string AI_VISIBILITY_PROBE = 'intel.ai_visibility_probe';

    public const string GBP_REVIEW_REPLY = 'gbp.review_reply';

    /** Faz 7: brand offerings vs Business Profile categories / services → missing services + category notes (one call). */
    public const string GBP_SERVICES_COMPARE = 'gbp.services_compare';

    /** Faz 7: proposed Business Profile description (≤ 750 characters) from brand memory, offerings and areas. */
    public const string GBP_DESCRIPTION = 'gbp.description';

    /** Operator's category / service list → Google categories (gcid) and service items to add to the profile (one call). */
    public const string GBP_PROFILE_PLAN = 'gbp.profile_plan';

    /** ADR-078: the planned posts of a location (one per slot: a page of the brand's site × an angle). */
    public const string GBP_POST_QUEUE = 'gbp.post_queue';

    /** ADR-079: the branch page of a Business Profile (text from the profile's facts and the brand's site; one call). */
    public const string GBP_BRANCH_PAGE = 'gbp.branch_page';

    /** Marka bakım ajanı: weekly, delta-driven review of one active brand from its Marka dosyası (≤ 5 tasks). */
    public const string BRAND_CARE = 'brand.care';

    /** Şef: Monday plan across the active brands from the care agents' notes and the open work counts. */
    public const string BRAND_CHIEF = 'brand.chief';

    /** Faz 7: one Business Profile post from one page of the brand's site (link to the page). */
    public const string GBP_POST_FROM_PAGE = 'gbp.post_from_page';

    /** Faz 6: Meta creative ideas / texts per main service, video hooks and test variants (one call). */
    public const string META_CREATIVES = 'meta.creatives';

    /** Faz 6: Meta campaign / ad set structure and remarketing proposals (one call). */
    public const string META_STRUCTURE = 'meta.structure';

    /** Faz 6: Meta lead form / landing page improvements (one call). */
    public const string META_LANDING = 'meta.landing';

    /** Kampanya → hizmet: the brand services of the Meta campaigns the rules could not match (one call per account). */
    public const string META_CAMPAIGN_SERVICES = 'meta.campaign_services';

    public const string INSIGHT_ADVISOR_EXPLAIN = 'insights.advisor_explain';

    public const string INSIGHT_REVIEW_THEMES = 'insights.review_themes';

    public const string INSIGHT_ALERT_CAUSE = 'insights.alert_cause';

    public const string INSIGHT_CUSTOMER_BRIEF = 'insights.customer_brief';

    public const string INSIGHT_LEAD_SCORE = 'insights.lead_score';

    public const string INSIGHT_TECHNICAL_TASKS = 'insights.technical_tasks';

    public const string SITE_FIX_VALUES = 'site_fixes.values';

    public const string SITE_FIX_LINKS = 'site_fixes.internal_links';

    public const string SITE_FIX_PAGE = 'site_fixes.page_writer';

    /** ADR-076: localize an article into another language of the site (title, slug, meta, body, internal links). */
    public const string CONTENT_LOCALIZE = 'content.localize';

    /** Faz 4: write one article of the content studio from an idea. */
    public const string CONTENT_ARTICLE = 'content.article';

    /** "Yeni fikir üret" (CONTENT_IDEAS_BLUEPRINT §4.3): extra content ideas of one cluster for the system-wide pool. */
    public const string CONTENT_IDEAS = 'content.ideas';

    /** "SEO analizi" (CONTENT_IDEAS_BLUEPRINT §8.3): the recipe for one content idea of a brand's site. */
    public const string SITE_CONTENT_RECIPE = 'site.content_recipe';

    /** Sorgular › Yasaklı ifadeler "AI ile öner" (CONTENT_IDEAS_BLUEPRINT §7): candidate forbidden phrases of a sector. */
    public const string COMPLIANCE_FORBIDDEN_TERMS = 'compliance.forbidden_terms';

    /** Faz 3 "AI ile kural üret": selected queries → new filter basket terms + new matching keywords per service (one call). */
    public const string QUERIES_FILTER_RULES = 'queries.filter_rules';

    /** Faz 3 "AI ile kümele": one service's queries → clusters (same user need on the same page type), one call. */
    public const string QUERIES_CLUSTER = 'queries.cluster';

    /** Sorgu otomatik pilotu: per query a service, a filter term (person / brand / irrelevant / forbidden) or none; applied without approval. */
    public const string QUERIES_TRIAGE = 'queries.triage';

    /** Sorgular "AI ile kümele" last step: merges / clarifies a service's clusters. */
    public const string QUERIES_CLUSTER_REVIEW = 'queries.cluster_review';

    /** "AI ile planla" adım 1: sectors of brands without one (+ clearly different asset sectors), one batched call. */
    public const string QUERIES_PLAN_SECTORS = 'queries.plan_sectors';

    /** "AI ile planla" adım 2: missing services + matching keyword fixes of the used sectors, one batched call. */
    public const string QUERIES_PLAN_SERVICES = 'queries.plan_services';

    /** "AI ile planla" adım 3: negative filter terms per used sector, one batched call. */
    public const string QUERIES_PLAN_FILTERS = 'queries.plan_filters';

    /** Filtre sepeti "Sorgularda tara": the sector's query words → brand / person / place / off-topic words for the basket. */
    public const string QUERIES_SCAN_FILTERS = 'queries.scan_filters';

    /** Sorgular "AI ile hizmet öner": unassigned library queries → a service of their sector (or none), 200 per call, all batches. */
    public const string QUERIES_ASSIGN_SERVICES = 'queries.assign_services';

    /** Faz 4b Rakipler: SERP result domains the rules could not place → ticari / bilgi / dizin / haber (batched). */
    public const string COMPETITORS_CLASSIFY = 'competitors.classify';

    /** Faz 4b Rakipler "Analiz et": one cluster's competitor pages vs our page → need, page type, gaps, suggestions. */
    public const string COMPETITORS_ANALYZE = 'competitors.analyze';

    /** Faz 4b Backlinkler: potential link sources by sector + brand areas (fee only with an evidence URL). */
    public const string BACKLINKS_SOURCES = 'backlinks.sources';

    /** Faz 4a Site: category of the pages the rules could not place (one batched call). */
    public const string SITE_PAGE_CATEGORIES = 'site.page_categories';

    /** Faz 4a Site AI adım 1: brand service ↔ hizmet / lokasyon page for pages the name rules could not match. */
    public const string SITE_SERVICE_PAGES = 'site.service_pages';

    /** Görsel alt metni: alt text for WordPress images without one (file name + page; operator approves the write). */
    public const string SITE_IMAGE_ALTS = 'site.image_alts';

    /** Faz 4a Site AI adım 2: coverage / intent judgement of ambiguous cluster ↔ page mappings (one call per service). */
    public const string SITE_CLUSTER_PAGES = 'site.cluster_pages';

    /** Küme ↔ içerik: which page of the site answers each cluster, read from page titles / headings / text (one call per service). */
    public const string SITE_CLUSTER_MATCH = 'site.cluster_match';

    /** Küme eksikleri: what a matched page does not answer of its clusters' queries, facets, AI questions and areas. */
    public const string SITE_CLUSTER_GAPS = 'site.cluster_gaps';

    /** AI sorguları: questions people ask AI assistants for each cluster ("{bölge}" where a place fits). */
    public const string QUERIES_AI_QUERIES = 'queries.ai_queries';

    /** Faz 4a brand memory: 2–4 sentence summary + key facts of pages used in analysis. */
    public const string SITE_PAGE_SUMMARY = 'site.page_summary';

    /** Faz 4a URL analizi: suggestions for one URL from its data pack. */
    public const string SITE_URL_ANALYSIS = 'site.url_analysis';

    /** Faz 4a "AI ile yap": the new version of the fields / HTML a suggestion changes. */
    public const string SITE_APPLY_CHANGE = 'site.apply_change';

    /** Faz 4a "Bu karardan standart öner": a scoped standard from an approved suggestion. */
    public const string SITE_STANDARD_FROM_DECISION = 'site.standard_from_decision';

    /** Faz 4a İçerik: weekly content plan (new pages / posts and updates). */
    public const string SITE_WEEKLY_CONTENT = 'site.weekly_content';

    /** Faz 4a İçerik: opportunities outside the approved clusters. */
    public const string SITE_CONTENT_DISCOVERY = 'site.content_discovery';

    /** Faz 4a İçerik "Taslak hazırla": full article HTML for a content suggestion. */
    public const string SITE_WRITE_ARTICLE = 'site.write_article';

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
