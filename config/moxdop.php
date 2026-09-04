<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        /*
         * Optional deployment override only. Normal installs derive the callback from
         * APP_URL + the named integrations.google.callback route (see GoogleOAuthRedirectUriResolver).
         */
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        /*
         * Application-level Google Ads API developer token (NOT an OAuth user token).
         */
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'ads_api_version' => env('GOOGLE_ADS_API_VERSION', 'v25'),
        /*
         * GBP uses https://www.googleapis.com/auth/business.manage (manage-level scope)
         * and often requires separate Google Business Profile API access approval.
         * Keep disabled until you intentionally enable that scope + API access.
         */
        'include_gbp_scope' => (bool) env('GOOGLE_INCLUDE_GBP_SCOPE', false),
        'gbp_discovery_enabled' => (bool) env('GOOGLE_GBP_DISCOVERY_ENABLED', false),
        'oauth_state_ttl_minutes' => (int) env('GOOGLE_OAUTH_STATE_TTL_MINUTES', 15),
        'access_token_refresh_skew_seconds' => (int) env('GOOGLE_ACCESS_TOKEN_REFRESH_SKEW_SECONDS', 60),
        'refresh_lock_seconds' => (int) env('GOOGLE_OAUTH_REFRESH_LOCK_SECONDS', 20),
    ],

    /*
     * Agency OpenAI Integration (Settings → Integrations).
     * Normal operation uses encrypted DB provider credentials (ADR-041).
     * Env OPENAI_API_KEY is optional bootstrap/fallback only — never shown in UI.
     * Route-owned models live under AI Control Plane (not Integration ownership).
     */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 20),
        /*
         * Legacy default model for OpenAI-only bootstrap / env override.
         * Website AI Guidance model selection is owned by the AI route.
         */
        'recommendation_model' => env('OPENAI_RECOMMENDATION_MODEL', 'gpt-5-mini'),
    ],

    /*
     * Agency Anthropic Integration (Settings → Integrations).
     * DB-first API key; ANTHROPIC_API_KEY is optional bootstrap/fallback only.
     */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 20),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
    ],

    /*
     * Agency Gemini Integration (Settings → Integrations).
     * Separate from Google OAuth Integration. DB-first API key; GEMINI_API_KEY fallback.
     */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 20),
    ],

    /*
     * AI Control Plane route defaults (workflow-specific; not a global ranking).
     */
    'ai' => [
        'defaults' => [
            'openai_model' => env('OPENAI_RECOMMENDATION_MODEL', 'gpt-5-mini'),
            'anthropic_model' => env('ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-5'),
            'gemini_model' => env('GEMINI_DEFAULT_MODEL', 'gemini-3.6-flash'),
        ],
    ],

    /*
     * Prospect public research fixtures for PHPUnit / Playwright E2E only.
     */
    'prospect_research' => [
        'fixtures' => (bool) env('MOXDOP_PROSPECT_RESEARCH_FIXTURES', false),
    ],

    /*
     * Agency Meta Integration (Settings → Integrations).
     *
     * Application / deployment configuration:
     *   Encrypted CoreIntegration provider credentials first (App ID / App Secret).
     *   META_APP_ID, META_APP_SECRET remain environment fallbacks — never shown in UI.
     *
     * Tenant authorization (legacy until Prompt 22 OAuth productionizes the flow):
     *   DB-first encrypted access token (system user / long-lived user token).
     *   META_ACCESS_TOKEN is optional bootstrap/fallback only — never shown in UI.
     *
     * API version is centralized; host is fixed to graph.facebook.com (no operator URL).
     */
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        /*
         * Optional deployment override. Normal installs derive callback from
         * APP_URL + integrations.meta.callback (see MetaOAuthRedirectUriResolver).
         */
        'redirect_uri' => env('META_REDIRECT_URI'),
        /*
         * Facebook Login for Business configuration ID from Meta App Dashboard.
         * Production dialog uses config_id (not scattered scope strings).
         */
        'login_configuration_id' => env('META_LOGIN_CONFIGURATION_ID'),
        /*
         * Legacy bootstrap / compatibility only — never shown in UI.
         * Production operator path is Connect Meta OAuth.
         */
        'access_token' => env('META_ACCESS_TOKEN'),
        'api_version' => env('META_API_VERSION', 'v26.0'),
        'timeout' => (int) env('META_TIMEOUT', 20),
        'max_pagination_pages' => (int) env('META_MAX_PAGINATION_PAGES', 20),
        'oauth_state_ttl_minutes' => (int) env('META_OAUTH_STATE_TTL_MINUTES', 15),
        'token_validation_ttl_seconds' => (int) env('META_TOKEN_VALIDATION_TTL_SECONDS', 900),
        'use_appsecret_proof' => (bool) env('META_USE_APPSECRET_PROOF', true),
    ],

    /*
     * Agency DataForSEO Integration (Settings → Integrations).
     * Normal operation uses encrypted DB provider credentials.
     * Env keys are optional bootstrap/fallback only — never shown in UI.
     */
    'dataforseo' => [
        'login' => env('DATAFORSEO_API_LOGIN', env('DATAFORSEO_LOGIN')),
        'password' => env('DATAFORSEO_API_PASSWORD', env('DATAFORSEO_PASSWORD')),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
        'timeout' => (int) env('DATAFORSEO_TIMEOUT', 30),
        /*
         * Free Labs locations/languages directory cache (provider metadata, not Evidence).
         * Official endpoint: GET /v3/dataforseo_labs/locations_and_languages (not charged).
         */
        'market_directory_cache_ttl_seconds' => (int) env('DATAFORSEO_MARKET_DIRECTORY_CACHE_TTL', 86400),
    ],

    /*
     * Sales Intent Radar (Batch B). Paid SERP is opt-in and never scheduled.
     */
    'sales_intent_discovery' => [
        'paid_calls_enabled' => (bool) env('MOXDOP_SALES_INTENT_PAID_CALLS', false),
        'fixtures' => (bool) env('MOXDOP_INTENT_SEARCH_FIXTURES', false),
        'max_queries_per_run' => (int) env('MOXDOP_SALES_INTENT_MAX_QUERIES', 5),
        'max_results_per_query' => (int) env('MOXDOP_SALES_INTENT_MAX_RESULTS', 10),
    ],

    /*
     * Website SEO Intelligence (DataForSEO Light V1) — MoxDOP cost/freshness policy.
     * TTL values are product decisions, not DataForSEO requirements.
     * Ranked Keywords source data updates weekly → multi-day TTL is appropriate.
     */
    'seo_intelligence' => [
        'ranked_keywords' => [
            'ttl_days' => (int) env('MOXDOP_SEO_RANKED_KEYWORDS_TTL_DAYS', 5),
            'limit' => (int) env('MOXDOP_SEO_RANKED_KEYWORDS_LIMIT', 100),
            'use_case' => 'website_ranked_keywords',
        ],
        'keywords_for_site' => [
            'ttl_days' => (int) env('MOXDOP_SEO_KEYWORDS_FOR_SITE_TTL_DAYS', 7),
            'limit' => (int) env('MOXDOP_SEO_KEYWORDS_FOR_SITE_LIMIT', 100),
            'min_search_volume' => (int) env('MOXDOP_SEO_KEYWORDS_FOR_SITE_MIN_VOLUME', 10),
            'use_case' => 'website_keywords_for_site',
        ],
        'opportunities' => [
            'max_rows' => 40,
            'high_volume' => 500,
            'medium_volume' => 100,
            'weak_rank_min' => 11,
            'weak_rank_max' => 30,
        ],
    ],

    /*
     * Search Demand Phase 7. Paid calls are manual-only and consent-gated.
     * USD rates are optional deployment estimates, never provider quotes.
     */
    'search_demand_enrichment' => [
        'freshness_days' => (int) env('MOXDOP_SEARCH_DEMAND_SERP_TTL_DAYS', 7),
        'max_queries_per_run' => (int) env('MOXDOP_SEARCH_DEMAND_SERP_MAX_QUERIES', 20),
        'default_depth' => (int) env('MOXDOP_SEARCH_DEMAND_SERP_DEPTH', 20),
        // Estimated cost per one 10-result SERP billing unit; depth 20 uses two units.
        'estimated_serp_cost_per_query_usd' => env('MOXDOP_SEARCH_DEMAND_SERP_COST_PER_QUERY_USD'),
        'estimated_keyword_metric_batch_cost_usd' => env('MOXDOP_SEARCH_DEMAND_KEYWORD_BATCH_COST_USD'),
        'estimated_keyword_expansion_batch_cost_usd' => env('MOXDOP_SEARCH_DEMAND_EXPANSION_BATCH_COST_USD'),
        'max_expansion_candidates' => (int) env('MOXDOP_SEARCH_DEMAND_MAX_EXPANSION_CANDIDATES', 50),
        'validation_top_results' => (int) env('MOXDOP_SEARCH_DEMAND_VALIDATION_TOP_RESULTS', 10),
        'validated_overlap_threshold' => (float) env('MOXDOP_SEARCH_DEMAND_VALIDATED_OVERLAP', 0.30),
        'conflict_overlap_threshold' => (float) env('MOXDOP_SEARCH_DEMAND_CONFLICT_OVERLAP', 0.10),
    ],

    /*
     * Search Demand Phase 8. Thresholds classify review candidates only;
     * they never apply URL ownership without an operator decision.
     */
    'search_demand_page_ownership' => [
        'max_candidates' => (int) env('MOXDOP_SEARCH_DEMAND_PAGE_MAX_CANDIDATES', 20),
        'dominance_threshold' => (float) env('MOXDOP_SEARCH_DEMAND_PAGE_DOMINANCE', 0.60),
    ],

    /* Phase 9 imports only already-stored observations and never calls a provider. */
    'search_demand_competitors' => [
        'max_import_candidates' => (int) env('MOXDOP_SEARCH_DEMAND_COMPETITOR_MAX_IMPORT', 100),
    ],
];
