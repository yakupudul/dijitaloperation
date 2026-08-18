<?php

namespace App\Support\Sales;

/**
 * Sales Intent Radar paid-call and fixture policy.
 * Default: paid discovery OFF. No page-load or scheduler triggers.
 */
final class IntentSearchConfig
{
    public const string CAPABILITY = 'public.intent.search';

    public const string PROVIDER = 'dataforseo';

    public const string USE_CASE = 'sales_intent_discovery';

    public const int MAX_QUERIES_PER_RUN = 5;

    public const int MAX_RESULTS_PER_QUERY = 10;

    public static function paidCallsEnabled(): bool
    {
        return (bool) config('moxdop.sales_intent_discovery.paid_calls_enabled', false);
    }

    public static function fixturesEnabled(): bool
    {
        return (bool) config('moxdop.sales_intent_discovery.fixtures', false);
    }

    public static function maxQueries(): int
    {
        $configured = (int) config('moxdop.sales_intent_discovery.max_queries_per_run', self::MAX_QUERIES_PER_RUN);

        return max(1, min(self::MAX_QUERIES_PER_RUN, $configured));
    }

    public static function maxResultsPerQuery(): int
    {
        $configured = (int) config('moxdop.sales_intent_discovery.max_results_per_query', self::MAX_RESULTS_PER_QUERY);

        return max(1, min(self::MAX_RESULTS_PER_QUERY, $configured));
    }
}
