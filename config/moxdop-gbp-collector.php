<?php

return [
    /*
     * Google Business Profile provider-owned historical reads.
     * Collection is operator-triggered from the bound GBP Digital Asset.
     */
    'performance_days' => (int) env('MOXDOP_GBP_PERFORMANCE_DAYS', 180),
    'search_keyword_months' => (int) env('MOXDOP_GBP_SEARCH_KEYWORD_MONTHS', 12),
];
