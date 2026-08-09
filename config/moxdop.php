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
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'ads_api_version' => env('GOOGLE_ADS_API_VERSION', 'v25'),
        /*
         * GBP uses https://www.googleapis.com/auth/business.manage (manage-level scope)
         * and often requires separate Google Business Profile API access approval.
         * Keep disabled until you intentionally enable that scope + API access.
         */
        'include_gbp_scope' => (bool) env('GOOGLE_INCLUDE_GBP_SCOPE', false),
        'gbp_discovery_enabled' => (bool) env('GOOGLE_GBP_DISCOVERY_ENABLED', false),
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
