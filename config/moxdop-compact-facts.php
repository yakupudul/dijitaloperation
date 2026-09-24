<?php

/*
 * Compact fact storage (PostgreSQL). High-cardinality daily facts keep their logical table name as a VIEW with the
 * same columns, while rows live in a narrow table: integer dictionary ids instead of repeated text (site, search
 * type, query, page, country, device…), int4 metrics, position as real, and one primary key. Provenance constants
 * (contract version, fingerprint, time zone, created/updated) are not stored per row.
 *
 * A Search Console query×page row went from ~1 KB (text columns, ~10 constant columns, 6 indexes, two of them
 * repeating the texts) to ~100 bytes. Readers are unchanged; PostgresWarehouseWriter writes compact rows once the
 * logical table has been converted (`php artisan moxdop:db:compact --execute`).
 *
 * logical table => [fact table, [dimension column => dictionary kind, …] in key order]
 */
return [
    'kinds' => [
        'site' => 1, 'search_type' => 2, 'query' => 3, 'page' => 4, 'country' => 5, 'device' => 6, 'searchAppearance' => 7,
    ],

    'tables' => [
        'gsc_query_daily' => ['fact' => 'gsc_f_query', 'dims' => ['query']],
        'gsc_page_daily' => ['fact' => 'gsc_f_page', 'dims' => ['page']],
        'gsc_query_page_daily' => ['fact' => 'gsc_f_query_page', 'dims' => ['query', 'page']],
        'gsc_query_country_daily' => ['fact' => 'gsc_f_query_country', 'dims' => ['query', 'country']],
        'gsc_query_device_daily' => ['fact' => 'gsc_f_query_device', 'dims' => ['query', 'device']],
        'gsc_page_country_daily' => ['fact' => 'gsc_f_page_country', 'dims' => ['page', 'country']],
        'gsc_page_device_daily' => ['fact' => 'gsc_f_page_device', 'dims' => ['page', 'device']],
        'gsc_search_appearance_page_daily' => ['fact' => 'gsc_f_appearance_page', 'dims' => ['searchAppearance', 'page']],
    ],

    /* Constant values the view returns for columns no longer stored per row. */
    'source_timezone' => 'America/Los_Angeles',
];
