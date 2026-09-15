<?php

return [
    /*
     * Google Business Profile provider-owned historical reads.
     * Collection runs on resource automation; manual bound GBP collection remains available.
     */
    'performance_days' => (int) env('MOXDOP_GBP_PERFORMANCE_DAYS', 180),
    'search_keyword_months' => (int) env('MOXDOP_GBP_SEARCH_KEYWORD_MONTHS', 12),

    /* Google GBP API content is not retained as an indefinite archive. */
    'content_retention_days' => (int) env('MOXDOP_GBP_CONTENT_RETENTION_DAYS', 30),
];
