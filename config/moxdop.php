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
     * Agency Meta Integration (Settings → Integrations).
     * DB-first encrypted access token (system user / long-lived user token).
     * META_ACCESS_TOKEN is optional bootstrap/fallback only — never shown in UI.
     * API version is centralized; host is fixed to graph.facebook.com (no operator URL).
     */
    'meta' => [
        'access_token' => env('META_ACCESS_TOKEN'),
        'api_version' => env('META_API_VERSION', 'v26.0'),
        'timeout' => (int) env('META_TIMEOUT', 20),
        'max_pagination_pages' => (int) env('META_MAX_PAGINATION_PAGES', 20),
    ],

    /*
     * Agency DataForSEO Integration (Settings → Integrations).
     * Normal operation uses encrypted DB provider credentials.
     * Env keys are optional bootstrap/fallback only — never shown in UI.
     */
    'dataforseo' => [
        'login' => env('DATAFORSEO_API_LOGIN'),
        'password' => env('DATAFORSEO_API_PASSWORD'),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
        'timeout' => (int) env('DATAFORSEO_TIMEOUT', 30),
        /*
         * Free Labs locations/languages directory cache (provider metadata, not Evidence).
         * Official endpoint: GET /v3/dataforseo_labs/locations_and_languages (not charged).
         */
        'market_directory_cache_ttl_seconds' => (int) env('DATAFORSEO_MARKET_DIRECTORY_CACHE_TTL', 86400),
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
];
