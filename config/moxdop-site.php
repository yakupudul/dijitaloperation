<?php

/*
|--------------------------------------------------------------------------
| Site ekranı (Faz 4b): Rakipler, Backlinkler, Site Sağlığı
|--------------------------------------------------------------------------
*/

return [
    'competitors' => [
        // SERP device (DataForSEO): mobile by default.
        'device' => env('MOXDOP_COMPETITORS_DEVICE', 'mobile'),
        // Location when no brand area resolves to a DataForSEO location (Turkey).
        'fallback_location_code' => (int) env('MOXDOP_COMPETITORS_FALLBACK_LOCATION', 2792),
        // Competitor pages fetched per cluster (commercial + informational competitors only).
        'pages_per_cluster' => (int) env('MOXDOP_COMPETITORS_PAGES_PER_CLUSTER', 5),
        // Competitor page text kept (characters) and re-fetch age (days).
        'content_chars' => 6000,
        'refetch_days' => 30,
        // Text of each competitor page sent to the analysis (characters).
        'analysis_chars' => 2500,
        // Domains classified without AI.
        'directory_domains' => [
            'doktortakvimi.com', 'doktorsitesi.com', 'hekimbul.com', 'sahibinden.com', 'yelp.com', 'foursquare.com',
            'tripadvisor.com', 'tripadvisor.com.tr', 'yandex.com.tr', 'google.com', 'facebook.com', 'instagram.com',
            'linkedin.com', 'youtube.com', 'x.com', 'twitter.com', 'tiktok.com', 'pinterest.com', 'sikayetvar.com',
            'armut.com', 'yellowpages.com.tr', 'bulurum.com', 'firmasec.com', 'eniyiler.com.tr', 'mynet.com',
            'whatclinic.com', 'bookimed.com', 'medicaltourism.com', 'placidway.com', 'clinicexpert.com',
        ],
        'news_domains' => [
            'hurriyet.com.tr', 'milliyet.com.tr', 'sabah.com.tr', 'sozcu.com.tr', 'haberturk.com', 'ntv.com.tr',
            'cnnturk.com', 'trthaber.com', 'aa.com.tr', 'ensonhaber.com', 'yeniakit.com.tr', 'posta.com.tr',
            'star.com.tr', 'takvim.com.tr', 'haber7.com', 'internethaber.com', 'odatv.com', 'bbc.com', 'bbc.co.uk',
            'medimagazin.com.tr', 'saglikhaberleri.com', 'onedio.com', 'webtekno.com', 'ankarahaber.com.tr',
        ],
        // Information sites (health portals, encyclopedias) classified without AI.
        'info_domains' => [
            'wikipedia.org',
            'saglik.gov.tr', 'nhs.uk', 'mayoclinic.org', 'healthline.com', 'webmd.com', 'medicalnewstoday.com',
        ],
    ],

    'backlinks' => [
        // Potential sources proposed per AI call.
        'max_sources' => 25,
    ],

    'health' => [
        // SSL / domain expiry are checked daily; within this many days the row is a warning.
        'warn_days' => 30,
        'rdap_base_url' => env('MOXDOP_RDAP_BASE_URL', 'https://rdap.org'),
    ],
];
