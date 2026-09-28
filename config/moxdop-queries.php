<?php

/**
 * Step 2 query pipeline (sorgu hattı): free query pulls from every discovered account, core-query normalization,
 * sector assignment, query → service matching, AI clustering and SERP page-type research.
 */
return [
    // Metric window (days) read from provider facts per account; Business Profile keywords use whole months.
    'window_days' => (int) env('MOXDOP_QUERIES_WINDOW_DAYS', 90),
    'gbp_months' => 3,
    // Most searched raw queries kept per account and source.
    'max_queries_per_source' => 5000,

    // AI sector assignment: accounts per call.
    'sector_batch_size' => 25,
    // AI query → service fallback: queries per call and per run.
    'classify_batch_size' => 60,
    'classify_per_run' => 600,
    // AI clustering: most searched queries per service sent to the model.
    'cluster_max_queries' => 250,
    'cluster_min_queries' => 3,
    'cluster_services_per_run' => 25,

    // SERP page-type research (paid, DataForSEO; the global spend guard still applies).
    'serp' => [
        'enabled' => (bool) env('MOXDOP_QUERIES_SERP', true),
        'cache_days' => 30,
        'per_run' => 40,
        'location_code' => 2792,
        'language_code' => 'tr',
    ],

    // "yakın / nerede" words removed like place names.
    'near_words' => ['yakın', 'yakınımda', 'yakınımdaki', 'yakınında', 'yakınındaki', 'yakınlarda', 'yakınlarında', 'en yakın',
        'nerede', 'nerde', 'neresi', 'civarı', 'civarında', 'civarındaki', 'çevresi', 'çevresinde', 'çevresindeki', 'near me', 'nearby'],

    // Default product / manufacturer brands per sector code (seeded once; editable on Sorgular › Ürün markaları).
    'product_brands' => [
        'dental' => ['Straumann', 'Nobel Biocare', 'Nobel', 'Osstem', 'Megagen', 'Bego', 'Hiossen', 'Neodent', 'Zimmer', 'Astra Tech',
            'Bredent', 'Dentsply', 'Implance', 'Invisalign', 'ClearCorrect', 'Clear Correct', 'Ivoclar', 'E.max'],
        'healthcare' => ['Straumann', 'Nobel Biocare', 'Osstem', 'Megagen', 'Invisalign'],
        'medical_aesthetics' => ['Juvederm', 'Restylane', 'Allergan', 'Galderma', 'Teoxane', 'Dysport'],
        'beauty' => ['Candela', 'Soprano'],
    ],
];
