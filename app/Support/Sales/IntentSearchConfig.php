<?php

namespace App\Support\Sales;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Intent Radar paid-call and fixture policy.
 * Paid discovery is opt-in and never scheduled.
 */
final class IntentSearchConfig
{
    public const string CAPABILITY = 'public.intent.search';

    public const string PROVIDER = 'dataforseo';

    public const string USE_CASE = 'sales_intent_discovery';

    public const string RUNTIME_PAID_CALLS_KEY = 'sales_intent_paid_calls_enabled';

    public const int MAX_QUERIES_PER_RUN = 5;

    public const int MAX_RESULTS_PER_QUERY = 10;

    public static function paidCallsEnabled(): bool
    {
        $deploymentDefault = (bool) config('moxdop.sales_intent_discovery.paid_calls_enabled', false);

        if (! Schema::hasTable('core_integrations')) {
            return $deploymentDefault;
        }

        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::DATAFORSEO)
            ->first();

        $config = is_array($integration?->config) ? $integration->config : [];
        if (array_key_exists(self::RUNTIME_PAID_CALLS_KEY, $config)) {
            return (bool) $config[self::RUNTIME_PAID_CALLS_KEY];
        }

        return $deploymentDefault;
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
