<?php

namespace App\Support\Async;

/**
 * Canonical async operation type identifiers stored on Run.metadata.operation_type.
 */
final class AsyncOperationTypes
{
    public const string BOUND_COLLECT = 'bound_collect';

    public const string WEBSITE_DIAGNOSIS = 'website_diagnosis';

    public const string PUBLIC_DISCOVERY = 'public_discovery';

    public const string SEO_INTELLIGENCE_REFRESH = 'seo_intelligence_refresh';

    public const string WEBSITE_AI_GUIDANCE = 'website_ai_guidance';

    public const string GOOGLE_ADS_AI_GUIDANCE = 'google_ads_ai_guidance';

    public const string META_ADS_AI_GUIDANCE = 'meta_ads_ai_guidance';

    public const string META_HISTORY_IMPORT = 'meta_history_import';

    public const string META_HISTORY_REFRESH = 'meta_history_refresh';

    public const string META_HISTORY_GAP_ENRICH = 'meta_history_gap_enrich';

    /**
     * Orchestration module_id for Activity Center Runs that wrap background work.
     */
    public const string MODULE_BOUND_COLLECT = 'bound-collect';

    public const string MODULE_PUBLIC_DISCOVERY = 'public-discovery';

    public const string MODULE_SEO_REFRESH = 'seo-intelligence-refresh';

    public const string MODULE_META_HISTORY = 'meta-history';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::BOUND_COLLECT => 'Collect live data',
            self::WEBSITE_DIAGNOSIS => 'Website diagnosis',
            self::PUBLIC_DISCOVERY => 'Public discovery',
            self::SEO_INTELLIGENCE_REFRESH => 'SEO intelligence refresh',
            self::WEBSITE_AI_GUIDANCE => 'Website AI guidance',
            self::GOOGLE_ADS_AI_GUIDANCE => 'Google Ads AI guidance',
            self::META_ADS_AI_GUIDANCE => 'Meta Ads AI guidance',
            self::META_HISTORY_IMPORT => 'Meta history import',
            self::META_HISTORY_REFRESH => 'Refresh Meta data',
            self::META_HISTORY_GAP_ENRICH => 'Preparing Meta history',
        ];
    }

    public static function label(string $operationType): string
    {
        return self::labels()[$operationType] ?? str($operationType)->replace('_', ' ')->title()->toString();
    }
}
