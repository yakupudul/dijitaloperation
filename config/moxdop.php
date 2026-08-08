<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/integrations/google/callback'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'ads_api_version' => env('GOOGLE_ADS_API_VERSION', 'v19'),
        /*
         * GBP uses https://www.googleapis.com/auth/business.manage (manage-level scope)
         * and often requires separate Google Business Profile API access approval.
         * Keep disabled until you intentionally enable that scope + API access.
         */
        'include_gbp_scope' => (bool) env('GOOGLE_INCLUDE_GBP_SCOPE', false),
        'gbp_discovery_enabled' => (bool) env('GOOGLE_GBP_DISCOVERY_ENABLED', false),
    ],
];
