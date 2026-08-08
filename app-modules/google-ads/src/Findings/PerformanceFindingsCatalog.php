<?php

namespace MoxDop\GoogleAds\Findings;

/**
 * Conservative starter thresholds for Google Ads performance Findings.
 * Tune only inside the google-ads module — Core stays threshold-free.
 */
final class PerformanceFindingsCatalog
{
    public const string SOURCE_MODULE = 'google-ads';

    public const string RULE_CONVERSIONS_DECLINE = 'google-ads:conversions-decline';

    public const string RULE_CPA_DETERIORATION = 'google-ads:cpa-deterioration';

    public const string RULE_SPEND_UP_CONVERSIONS_DOWN = 'google-ads:spend-up-conversions-down';

    public const string RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS = 'google-ads:campaign-spend-zero-conversions';

    /** @var list<string> */
    public const array ACCOUNT_RULE_IDS = [
        self::RULE_CONVERSIONS_DECLINE,
        self::RULE_CPA_DETERIORATION,
        self::RULE_SPEND_UP_CONVERSIONS_DOWN,
    ];

    /** @var list<string> */
    public const array CAMPAIGN_RULE_IDS = [
        self::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS,
    ];

    public const float CONVERSIONS_PREV_MIN = 10.0;

    public const float CONVERSIONS_ABS_DROP_MIN = 5.0;

    public const float CONVERSIONS_PCT_DROP_MIN = 30.0;

    public const float CPA_PREV_CONVERSIONS_MIN = 10.0;

    public const float CPA_CURRENT_CONVERSIONS_MIN = 5.0;

    public const float CPA_PREV_COST_MIN = 50.0;

    public const float CPA_PCT_INCREASE_MIN = 40.0;

    public const float SPEND_UP_PREV_COST_MIN = 50.0;

    public const float SPEND_UP_PCT_MIN = 20.0;

    public const float SPEND_UP_CONVERSIONS_PCT_DROP_MIN = 20.0;

    public const float CAMPAIGN_COST_MIN = 50.0;

    public const float CAMPAIGN_CLICKS_MIN = 30.0;

    public const int CAMPAIGN_FINDINGS_MAX = 10;
}
