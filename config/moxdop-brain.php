<?php

/*
 * Hizmet Beyni — thresholds of the method engine and of outcome measurement. Editable on screen
 * (Ayarlar → Yöntem Kütüphanesi); these are the defaults.
 */
return [
    'methods' => [
        // A "successful pages have X" hypothesis needs this much evidence in one cohort (service × page type).
        'min_pages' => (int) env('MOXDOP_BRAIN_MIN_PAGES', 30),
        'min_brands' => (int) env('MOXDOP_BRAIN_MIN_BRANDS', 8),
        // Share of top pages minus share of bottom pages having the feature.
        'min_lift' => 0.3,
        // At least this share of top pages has the feature.
        'min_top_rate' => 0.6,
        // Top / bottom groups by score percentile.
        'top_quantile' => 0.66,
        'bottom_quantile' => 0.33,
    ],
    'validation' => [
        // Applied recommendations of one method needed before its effect is judged.
        'min_treated' => 5,
        // Share of treated pages that must beat their controls for "validated".
        'min_positive_share' => 0.6,
    ],
    'measure' => [
        'after_days' => 28,
        'second_after_days' => 56,
        'gsc_lag_days' => 3,
    ],
    'gaps' => [
        // A cluster needs at least this many impressions on the site (or demand) before "create a page" is raised.
        'min_cluster_queries' => 3,
    ],
];
