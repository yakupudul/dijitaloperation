<?php

namespace App\Services\Collection\Providers\MetaAds;

/**
 * Official Meta Marketing / Insights capability notes verified for Prompt 24.
 */
final class MetaAdsProviderCapabilities
{
    public const string VERIFICATION_DATE = '2026-08-13';

    public const string GRAPH_API_VERSION = 'v26.0';

    public const string PROVIDER_COMPLETENESS = 'PROVIDER_REPORT_BOUNDED';

    public const string MONEY_UNIT_NOTE = 'Meta Ad Account budgets use account minor currency units (typically cents). Insights spend is a decimal string in major units. Google Ads micros MUST NOT be assumed.';

    public const string ATTRIBUTION_NOTE = 'Insights requests set use_unified_attribution_setting=true per META_ADS_DATA_CONTRACT_V1.';

    public const string REACH_NOTE = 'Reach is NON_ADDITIVE across days. Frequency is NON_ADDITIVE / not blindly averaged.';

    public const string ASYNC_POST_NOTE = 'POST act_*/insights creates a read-only Ad Report Run. It is not an advertising configuration mutation.';
}
